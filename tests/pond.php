<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';
require __DIR__ . '/../pond/Pond.php';
require __DIR__ . '/../pond/RecoveryBundle.php';

use Respawn\Pond\Pond;
use Respawn\Protection\ProtectionReconciler;
use Respawn\Provider\ConnectionIdentity;
use Respawn\Provider\HttpsProvider;
use Respawn\Reconstruction\Blueprint;
use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\ConfigurationCapture;
use Respawn\Reconstruction\FileSecrets;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SecretResolver;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function pondAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
function pondRemove(string $path): void
{
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
    foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { pondRemove($path . '/' . $entry); } }
    rmdir($path);
}
function pondDenied(callable $operation, string $message): void
{
    $denied = false;
    try { $operation(); } catch (Throwable) { $denied = true; }
    pondAssert($denied, $message);
}

function pondConfigurationCapture(array $blueprint): array
{
    $modules = [];
    foreach ($blueprint['modules'] as $name=>$version) {
        $modules[$name] = ['available'=>true,'version'=>$version,'discoveryState'=>'known'];
    }
    $collections = ['core.users'=>['state'=>'captured','objects'=>[]],
        'core.pjsip'=>['state'=>'captured','objects'=>[]],'ringgroups'=>['state'=>'captured','objects'=>[]]];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        $type = $operation['object']['type'];
        $collection = match ($type) { 'user'=>'core.users','pjsip'=>'core.pjsip',default=>'ringgroups' };
        $collections[$collection]['objects'][] = ['id'=>$operation['object']['identity'],
            'type'=>$operation['adapter'],'state'=>'captured','fields'=>$operation['parameters']];
    }
    return ConfigurationCapture::create($blueprint['sourcePbxId'],$modules,$collections,
        ['state'=>'not_configured','references'=>[],'recoveryReady'=>false,'reasonCode'=>'SECRET_MANIFEST_MISSING'],
        ['CREDENTIALS:SECRET_MANIFEST_MISSING']);
}

umask(0077);
$root = sys_get_temp_dir() . '/respawn-pond-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$server = null;
try {
    mkdir($root . '/pond', 0700);
    mkdir($root . '/source', 0700);
    file_put_contents($root . '/key', random_bytes(32));
    chmod($root . '/key', 0600);
    $configuration = ['schema'=>'respawn.provider-config.v1', 'adapterId'=>'standalone-pond',
        'endpoint'=>'https://pond.example.invalid/rpc', 'credentialRef'=>$root . '/source/token',
        'sourcePbxId'=>'pbx-alpha', 'allowedHosts'=>['pond.example.invalid']];
    $connection = ConnectionIdentity::forConfiguration($configuration);
    $sourceToken = bin2hex(random_bytes(32));
    $recoveryToken = bin2hex(random_bytes(32));
    $otherToken = bin2hex(random_bytes(32));
    file_put_contents($configuration['credentialRef'], $sourceToken);
    $pond = new Pond($root . '/pond', $root . '/key');
    $pond->enrol($sourceToken, 'tenant-a', 'pbx-alpha', $connection, ['retain','read','escrow','recover']);
    $pond->enrol($recoveryToken, 'tenant-a', 'pbx-alpha', $connection, ['read','recover']);
    $pond->enrol($otherToken, 'tenant-b', 'pbx-alpha', $connection, ['read','recover']);
    unset($pond);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $start = static function () use ($root, $address) {
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', __DIR__ . '/../pond/public'],
            [0=>['pipe','r'], 1=>['file', $root . '/server.log','a'], 2=>['file',$root . '/server.log','a']], $pipes,
            null, array_merge(getenv(), ['RESPAWN_POND_STATE'=>$root . '/pond', 'RESPAWN_POND_KEY'=>$root . '/key', 'RESPAWN_POND_LOOPBACK_TEST'=>'1']));
        if (!is_resource($process)) { throw new RuntimeException('POND_TEST_START_FAILED'); }
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if (is_resource($probe)) { fclose($probe); return $process; }
        } while (microtime(true) < $deadline);
        proc_terminate($process); proc_close($process);
        throw new RuntimeException('POND_TEST_START_FAILED');
    };
    $server = $start();
    $transport = static function (string $token) use ($address): Closure {
        return static function (array $request) use ($token, $address): array {
            $curl = curl_init('http://' . $address . '/');
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($request, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_PROXY=>'']);
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 200 || !is_string($body)) { throw new RuntimeException('POND_TEST_HTTP_REJECTED_' . $status); }
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        };
    };
    $provider = new HttpsProvider($configuration, $transport($sourceToken));
    $blueprint = SimulatedPbx::blueprint('pbx-alpha');
    $configurationCapture = pondConfigurationCapture($blueprint);
    $captureRevisionId = 'rev-' . str_repeat('e',32);
    $captureAck = $provider->retainConfigurationCapture('pbx-alpha',$captureRevisionId,$configurationCapture);
    $duplicateCaptureAck = $provider->retainConfigurationCapture('pbx-alpha',$captureRevisionId,$configurationCapture);
    pondAssert(($captureAck['ack']['contentHash'] ?? '') === $configurationCapture['contentHash']
        && $duplicateCaptureAck['ack'] === $captureAck['ack']
        && ($captureAck['recoveryReady'] ?? true) === false,
        'Stream durably retains a secret-free capture with stable retry acknowledgement and no recovery-ready claim');
    $readBackCapture = $provider->retrieveConfigurationCapture('pbx-alpha',$captureRevisionId);
    pondAssert(($readBackCapture['capture']['contentHash'] ?? '') === $configurationCapture['contentHash']
        && $readBackCapture['capture'] === $configurationCapture
        && ($readBackCapture['recoveryReady'] ?? true) === false,
        'independent Pond read-back returns the exact capture and verifies its content hash');
    $otherTenantProvider = new HttpsProvider($configuration,$transport($otherToken));
    pondDenied(static fn () => $otherTenantProvider->retrieveConfigurationCapture('pbx-alpha',$captureRevisionId),
        'capture retrieval remains isolated to its enrolled Pond tenant');
    $pondDatabase = new PDO('sqlite:' . $root . '/pond/pond.sqlite');
    $captureScope = hash('sha256','tenant-a' . "\0" . 'pbx-alpha');
    $tamperedCapture = $configurationCapture;
    $tamperedCapture['collections']['core.users']['objects'][0]['fields']['name'] = 'Tampered';
    $pondDatabase->prepare('UPDATE objects SET body=? WHERE scope=? AND kind=? AND id=?')->execute([
        json_encode(['capture'=>$tamperedCapture,'ack'=>$captureAck['ack']],JSON_THROW_ON_ERROR),
        $captureScope,'configurationCapture',$captureRevisionId]);
    pondDenied(static fn () => $provider->retrieveConfigurationCapture('pbx-alpha',$captureRevisionId),
        'capture read-back rejects stored data whose content hash no longer matches');
    $pondDatabase->prepare('UPDATE objects SET body=? WHERE scope=? AND kind=? AND id=?')->execute([
        json_encode(['capture'=>$configurationCapture,'ack'=>$captureAck['ack']],JSON_THROW_ON_ERROR),
        $captureScope,'configurationCapture',$captureRevisionId]);
    unset($pondDatabase);
    $manifest = ['sourcePbxId'=>'pbx-alpha','references'=>[]];
    $originalSecrets = [];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        foreach ($operation['secretRefs'] as $reference) {
            $path = $root . '/source/' . hash('sha256', $reference);
            $secret = 'Synthetic-' . bin2hex(random_bytes(16));
            file_put_contents($path, $secret);
            chmod($path, 0600);
            $manifest['references'][$reference] = $path;
            $originalSecrets[$reference] = hash('sha256', $secret);
        }
    }
    file_put_contents($root . '/source/secrets.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    chmod($root . '/source/secrets.json', 0600);
    $revisionStore = new RevisionStore(new JsonStateStore($root . '/source/revisions'));
    $reconciler = new ProtectionReconciler(new JsonStateStore($root . '/source/outbox'), $revisionStore,
        $provider, 'pbx-alpha', 300, new FileSecrets($root . '/source/secrets.json'));
    $now = time();
    $missingRevision = 'rev-' . str_repeat('1', 32);
    $missingKey = hash('sha256', $connection . "\0pbx-alpha\0" . $missingRevision . "\0" . $blueprint['contentHash']);
    pondDenied(static fn () => $provider->retainRevision('pbx-alpha', $blueprint, $missingRevision, $missingKey),
        'Pond cannot acknowledge a blueprint whose required credentials have not been persisted');
    pondDenied(static fn () => $provider->retrieveRevision('pbx-alpha', $missingRevision),
        'failed credential prerequisite leaves no retained revision or success receipt');
    $initial = $reconciler->reconcile(static fn (): array => $blueprint, true, $now);
    pondAssert($initial['latestVerifiedRevisionId'] === $initial['latestLocalRevisionId'] && $initial['pendingRevisions'] === 0,
        'independent HTTP Pond durably retains and retrieves the automatically captured revision');
    pondAssert(!$initial['credentialsIndependentlyRecoverable'] && $initial['retentionEvidenceMode'] === 'INJECTED_TRANSPORT',
        'loopback test transport is not elevated to external TLS deployment evidence');
    $unchanged = $reconciler->reconcile(static fn (): array => $blueprint, true, $now + 300);
    pondAssert($initial['latestLocalRevisionId'] === $unchanged['latestLocalRevisionId'], 'unchanged capture does not create another transaction');
    $operations = BuildSpec::plan($blueprint);
    $users = []; $devices = []; $groups = [];
    foreach ($operations as $operation) {
        $number = $operation['object']['identity'];
        if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
        elseif ($operation['object']['type'] === 'pjsip') {
            $reference = $operation['secretRefs']['authPassword'];
            if ($number === '1001') {
                $reference = 'secret:pbx-alpha:1001:v2';
                $secret = 'Rotated-' . bin2hex(random_bytes(16));
                $path = $root . '/source/rotated';
                file_put_contents($path, $secret);
                chmod($path, 0600);
                $manifest['references'][$reference] = $path;
                $manifest['activeReferences']['1001'] = $reference;
                $originalSecrets[$reference] = hash('sha256', $secret);
            }
            $devices[$number] = $operation['parameters'] + ['secretRef'=>$reference];
        } else {
            $group = ['number'=>$number] + $operation['parameters'];
            $group['description'] .= ' changed';
            $groups[] = $group;
        }
    }
    file_put_contents($root . '/source/secrets.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $changed = BuildSpec::create('pbx-alpha', $blueprint['modules'], $users, $devices, $groups);
    $sourceResolver = new FileSecrets($root . '/source/secrets.json');
    pondAssert($sourceResolver->reference('pbx-alpha', '1001') === 'secret:pbx-alpha:1001:v2'
        && hash_equals($originalSecrets['secret:pbx-alpha:1001:v1'], hash('sha256', $sourceResolver->resolve('secret:pbx-alpha:1001:v1', 'pbx-alpha'))),
        'active reference selects rotated password while queued historical snapshots can still resolve their original version');
    $latest = $reconciler->reconcile(static fn (): array => $changed, true, $now + 600);
    pondAssert($latest['latestVerifiedRevisionId'] !== $initial['latestVerifiedRevisionId'] && $latest['pendingRevisions'] === 0,
        'changed configuration and rotated credentials are captured and published together');
    $revision = $revisionStore->get('pbx-alpha', $latest['latestLocalRevisionId']);
    $key = hash('sha256', $connection . "\0pbx-alpha\0" . $revision['revisionId'] . "\0" . $changed['contentHash']);
    $firstAck = $provider->retainRevision('pbx-alpha', $changed, $revision['revisionId'], $key);
    $secondAck = $provider->retainRevision('pbx-alpha', $changed, $revision['revisionId'], $key);
    pondAssert($firstAck === $secondAck, 'duplicate submissions return the same immutable persisted receipt');
    $replay = ['schema'=>'respawn.provider-rpc.v2', 'connectionId'=>$connection, 'pbxId'=>'pbx-alpha',
        'method'=>'status', 'parameters'=>[], 'requestId'=>bin2hex(random_bytes(16)), 'sentAt'=>gmdate(DATE_ATOM)];
    $transport($sourceToken)($replay);
    pondDenied(static fn () => $transport($sourceToken)($replay), 'replayed authenticated request is rejected');
    $other = new HttpsProvider($configuration, $transport($otherToken));
    pondDenied(static fn () => $other->retrieveRevision('pbx-alpha', $revision['revisionId']), 'same PBX identity in another tenant cannot retrieve this revision');
    pondDenied(static fn () => $provider->retrieveCredential('pbx-bravo', 'secret:pbx-bravo:2001:v1'),
        'enrolled source cannot request another PBX credential');
    $expired = $replay;
    $expired['requestId'] = bin2hex(random_bytes(16));
    $expired['sentAt'] = gmdate(DATE_ATOM, time() - 120);
    pondDenied(static fn () => $transport($sourceToken)($expired), 'expired request is rejected even with valid bearer authentication');
    pondDenied(static fn () => (new HttpsProvider($configuration, $transport(str_repeat('0',64))))->capabilities(), 'unknown bearer cannot access the Pond');
    $reference = 'secret:pbx-alpha:1001:v2';
    pondDenied(static fn () => $provider->escrowCredential('pbx-alpha', $reference, 'Different-Password-123456', hash('sha256', 'conflict')),
        'existing versioned credential reference cannot acquire a different password');
    $database = file_get_contents($root . '/pond/pond.sqlite');
    foreach ($manifest['references'] as $path) {
        pondAssert(!str_contains($database, file_get_contents($path)), 'actual retained password is absent from SQLite plaintext');
    }
    unset($reconciler, $revisionStore, $provider, $manifest, $blueprint, $changed, $revision, $sourceToken, $secret, $sourceResolver);
    pondRemove($root . '/source');
    proc_terminate($server); proc_close($server); $server = $start();
    pondAssert(!file_exists($root . '/source'), 'original source files, manifest, token, revisions and outbox are unavailable');
    $database = new PDO('sqlite:' . $root . '/pond/pond.sqlite');
    $storedQuery = $database->prepare('SELECT body FROM objects WHERE scope=? AND kind=? AND id=?');
    $storedQuery->execute([hash('sha256', "tenant-a\0pbx-alpha"),'configurationCapture',$captureRevisionId]);
    $storedCaptureRecord = json_decode($storedQuery->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
    pondAssert(ConfigurationCapture::canonicalBytes($storedCaptureRecord['capture'])
        === ConfigurationCapture::canonicalBytes($configurationCapture),
        'capture payload is byte-equivalent in SQLite after process restart');
    unset($database, $storedQuery, $storedCaptureRecord);
    $restartedPond = new Pond($root . '/pond',$root . '/key');
    $restartedCapture = $restartedPond->dispatch($recoveryToken,['schema'=>'respawn.provider-rpc.v2',
        'connectionId'=>$connection,'method'=>'retrieveConfigurationCapture','pbxId'=>'pbx-alpha',
        'parameters'=>['pbxId'=>'pbx-alpha','revisionId'=>$captureRevisionId],
        'requestId'=>bin2hex(random_bytes(16)),'sentAt'=>gmdate(DATE_ATOM)]);
    pondAssert(($restartedCapture['capture']['contentHash'] ?? '') === $configurationCapture['contentHash'],
        'restarted Pond storage retains the capture object under the authorised read scope');
    unset($restartedPond);
    $recovery = new HttpsProvider($configuration, $transport($recoveryToken));
    $retainedCaptureAfterRestart = $recovery->retrieveConfigurationCapture('pbx-alpha',$captureRevisionId);
    pondAssert(($retainedCaptureAfterRestart['capture']['contentHash'] ?? '') === $configurationCapture['contentHash'],
        'capture-only revision remains independently retrievable after Pond process restart');
    $retained = $recovery->retrieveLatestRevision('pbx-alpha');
    Blueprint::verifyHash($retained['blueprint']);
    pondAssert($retained['revisionId'] === $latest['latestVerifiedRevisionId'], 'restarted independent Pond retrieves latest acknowledged revision after source loss');
    $pondStatus = $recovery->status();
    pondAssert(($pondStatus['lastReceivedRevisionId'] ?? '') === $retained['revisionId']
        && ($pondStatus['configurationHash'] ?? '') === $retained['blueprint']['contentHash']
        && ($pondStatus['storageHealth'] ?? '') === 'healthy'
        && ($pondStatus['recoveryInformationAvailable'] ?? false) === true
        && ($pondStatus['retainedCredentialReferenceCount'] ?? 0) > 0,
        'authenticated Pond status reports its retained revision, healthy SQLite storage, and verified recovery credentials');
    $databaseDigest = hash_file('sha256', $root . '/pond/pond.sqlite');
    $localHealth = \Respawn\Pond\Pond::health($root . '/pond', $root . '/key');
    pondAssert(($localHealth['state'] ?? '') === 'healthy' && ($localHealth['readOnly'] ?? false) === true
        && ($localHealth['retainedRevisionCount'] ?? 0) > 0 && ($localHealth['encryptedCredentialCount'] ?? 0) > 0
        && ($localHealth['recoveryMaterialAvailable'] ?? false) === true
        && hash_file('sha256', $root . '/pond/pond.sqlite') === $databaseDigest,
        'local Pond health command verifies recovery material and reports integrity without modifying SQLite');
    pondAssert($recovery->retrieveRevision('pbx-alpha', $initial['latestVerifiedRevisionId'])['revisionId'] === $initial['latestVerifiedRevisionId'],
        'older revision and original credential references survive rotation');
    pondDenied(static fn () => $recovery->escrowCredential('pbx-alpha', $reference, 'Different-Password-123456', hash('sha256','no-write')),
        'recovery-only principal cannot modify retained credentials');
    $bundle = \Respawn\Pond\RecoveryBundle::write($recovery, 'pbx-alpha', $root . '/recovery');
    pondDenied(static fn () => \Respawn\Pond\RecoveryBundle::write($recovery, 'pbx-alpha', $root . '/recovery'),
        'recovery never overwrites an existing bundle or unrelated destination');
    symlink($root . '/recovery', $root . '/recovery-link');
    pondDenied(static fn () => \Respawn\Pond\RecoveryBundle::write($recovery, 'pbx-alpha', $root . '/recovery-link'),
        'recovery rejects a symlinked destination');
    $resolver = new FileSecrets($root . '/recovery/secrets.json');
    foreach (BuildSpec::plan($retained['blueprint']) as $operation) {
        foreach ($operation['secretRefs'] as $reference) {
            pondAssert(hash_equals($originalSecrets[$reference], hash('sha256', $resolver->resolve($reference, 'pbx-alpha'))),
                'recovery CLI bundle contains the exact original versioned password, not a replacement');
        }
    }
    pondAssert($bundle['revisionId'] === $retained['revisionId'], 'recovery CLI bundle binds its manifest to the latest retained blueprint');
    $result = (new BuildExecutor(new SimulatedPbx(new JsonStateStore($root . '/target'), 'pbx-replacement'),
        new JsonStateStore($root . '/target-build'), $resolver))->execute($retained['blueprint'], true);
    pondAssert(($result['state'] ?? '') === 'configuration_verified',
        'existing reconstruction engine verifies simulated target with exact original/rotated passwords obtained only from independent Pond');
    $savedKey = file_get_contents($root . '/key');
    file_put_contents($root . '/key', random_bytes(32));
    $wrongKeyHealth = \Respawn\Pond\Pond::health($root . '/pond', $root . '/key');
    pondAssert(($wrongKeyHealth['state'] ?? '') === 'degraded'
        && ($wrongKeyHealth['recoveryMaterialAvailable'] ?? true) === false,
        'Pond health refuses recovery readiness when the configured master key does not match escrow records');
    pondDenied(static fn () => $recovery->retrieveCredential('pbx-alpha', $reference), 'wrong Pond decryption key cannot return a credential');
    file_put_contents($root . '/key', $savedKey);
    $database = new PDO('sqlite:' . $root . '/pond/pond.sqlite');
    $query = $database->prepare('SELECT scope,body FROM objects WHERE kind=? AND id=?');
    $query->execute(['credential', $reference]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    $tampered = json_decode($row['body'], true, 16, JSON_THROW_ON_ERROR);
    $tampered['tag'] = base64_encode(str_repeat("\0", 16));
    $database->prepare('UPDATE objects SET body=? WHERE scope=? AND kind=? AND id=?')->execute([
        json_encode($tampered, JSON_THROW_ON_ERROR), $row['scope'], 'credential', $reference]);
    pondDenied(static fn () => $recovery->retrieveCredential('pbx-alpha', $reference), 'AES-GCM authentication rejects tampered retained ciphertext');
    $database->prepare('UPDATE objects SET body=? WHERE scope=? AND kind=? AND id=?')->execute([
        $row['body'], $row['scope'], 'credential', $reference]);
    unset($database);
    $serverLog = file_get_contents($root . '/server.log');
    foreach (glob($root . '/recovery/*.secret') as $secretPath) {
        pondAssert(!str_contains($serverLog, file_get_contents($secretPath)), 'service access/error logs do not expose recovered passwords');
    }
    $pond = new Pond($root . '/pond', $root . '/key');
    $pond->revoke($recoveryToken);
    pondDenied(static fn () => $recovery->retrieveLatestRevision('pbx-alpha'), 'revocation removes recovery access without deleting retained data');
    echo "Standalone Pond lifecycle passed: real SQLite/AES-GCM and independent loopback HTTP process; synthetic source and target, not FreePBX or public TLS.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    pondRemove($root);
}