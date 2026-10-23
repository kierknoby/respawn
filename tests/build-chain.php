<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\MemorySecrets;
use Respawn\Reconstruction\FileSecrets;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

if (isset($argv[1], $argv[2])) {
    $workerRoot = $argv[2];
    $workerJournal = new JsonStateStore($workerRoot . '/journal');
    if ($argv[1] === 'hold') {
        $workerJournal->exclusive('target', static function (): void { echo "LOCKED\n"; flush(); fgets(STDIN); });
        exit(0);
    }
    $workerSecrets = new MemorySecrets(json_decode(file_get_contents($workerRoot . '/secrets.json'), true, 12, JSON_THROW_ON_ERROR));
    $workerTarget = new SimulatedPbx(new JsonStateStore($workerRoot . '/target'), 'target');
    $workerExecutor = new BuildExecutor($workerTarget, $workerJournal, $workerSecrets,
        static function (string $stage, string $id): void { if ($id === 'user:1001') { exit(73); } });
    $workerExecutor->execute(SimulatedPbx::blueprint('pbx-alpha'), true);
    exit(1);
}

$checks = 0;
function invariant(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++; echo 'PASS: ' . $message . "\n";
}
$root = sys_get_temp_dir() . '/respawn-chain-' . bin2hex(random_bytes(8));
try {
    $blueprint = SimulatedPbx::blueprint('pbx-alpha');
    $values = [];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        foreach ($operation['secretRefs'] as $reference) { $values[$reference] = bin2hex(random_bytes(24)); }
    }
    $secrets = new MemorySecrets($values);
    if (!is_dir($root)) { mkdir($root, 0700, true); }
    $firstReference = array_key_first($values);
    $secretPath = $root . '/protected-secret';
    file_put_contents($secretPath, $values[$firstReference]); chmod($secretPath, 0600);
    $manifestPath = $root . '/manifest.json';
    file_put_contents($manifestPath, json_encode(['sourcePbxId' => 'pbx-alpha', 'references' => [$firstReference => $secretPath]])); chmod($manifestPath, 0600);
    $fileSecrets = new FileSecrets($manifestPath);
    invariant(hash_equals($values[$firstReference], $fileSecrets->resolve($firstReference, 'pbx-alpha')), 'protected identity-bound file reference resolves original credential without printing it');
    foreach (['permissions','identity','path'] as $case) {
        if ($case === 'permissions') { chmod($manifestPath, 0644); clearstatcache(); }
        try {
            if ($case === 'path') { (new FileSecrets($root . '/../unsafe'))->resolve($firstReference, 'pbx-alpha'); }
            else { $fileSecrets->resolve($firstReference, $case === 'identity' ? 'pbx-bravo' : 'pbx-alpha'); }
            throw new LogicException('Unsafe secret resolver input accepted.');
        } catch (RuntimeException $exception) { invariant(true, 'secret resolver rejects ' . $case); }
        chmod($manifestPath, 0600); clearstatcache();
    }
    $unsafeParent = $root . '/world-writable-parent';
    mkdir($unsafeParent, 0777);
    chmod($unsafeParent, 0777);
    $unsafeParentManifest = $unsafeParent . '/manifest.json';
    file_put_contents($unsafeParentManifest, json_encode(['sourcePbxId' => 'pbx-alpha', 'references' => [$firstReference => $secretPath]]));
    chmod($unsafeParentManifest, 0600);
    try {
        (new FileSecrets($unsafeParentManifest))->resolve($firstReference, 'pbx-alpha');
        throw new LogicException('Credential file in an untrusted parent directory was accepted.');
    } catch (RuntimeException $exception) {
        invariant($exception->getMessage() === 'SECRET_MANIFEST_UNSAFE', 'secret resolver rejects a private manifest in a world-writable parent');
    } finally { chmod($unsafeParent, 0700); }
    $rotated = $blueprint;
    foreach ($rotated['operations'] as &$operation) {
        if ($operation['object']['type'] === 'pjsip') { $operation['secretRefs']['authPassword'] = substr($operation['secretRefs']['authPassword'], 0, -2) . 'v2'; }
    }
    unset($operation);
    BuildSpec::plan($rotated);
    invariant(BuildSpec::seal($rotated)['contentHash'] !== $blueprint['contentHash'], 'immutable credential revision changes blueprint hash without a public password/hash');
    $scenario = function (string $name) use ($root, $secrets): array {
        $store = new JsonStateStore($root . '/' . $name . '/target');
        $target = new SimulatedPbx($store, 'target');
        $journal = new JsonStateStore($root . '/' . $name . '/journal');
        return [$target, $store, $journal, new BuildExecutor($target, $journal, $secrets)];
    };
    $source = new SimulatedPbx(new JsonStateStore($root . '/source'), 'source');
    foreach (BuildSpec::plan($blueprint) as $operation) { $source->create($operation, $secrets, 'pbx-alpha'); }
    $exported = $source->export('pbx-alpha', array_column(BuildSpec::plan($blueprint), 'id'), $secrets);
    invariant($exported === $blueprint, 'independent source export reproduces selected user/device/group intent');
    $objects = new JsonStateStore($root . '/target');
    $target = new SimulatedPbx($objects, 'target');
    $journal = new JsonStateStore($root . '/journal');
    $executor = new BuildExecutor($target, $journal, $secrets);
    $result = $executor->execute($exported, true);
    invariant($result['state'] === 'configuration_verified', 'fresh target reconstructs complete selected chain');
    invariant(count($target->snapshot()['objects']) === 6, 'two users, two devices and two groups exist on target');
    invariant($target->export('pbx-alpha', array_column(BuildSpec::plan($blueprint), 'id'), $secrets) === $exported, 'target export matches source supported configuration and auth references');
    invariant($target->snapshot()['applyCount'] === 1 && !$result['runtimeVerified'] && !$result['callsVerified'], 'single apply is distinguished from runtime and live-call evidence');
    $executor->execute($exported, true);
    invariant(count($target->snapshot()['creationOrder']) === 6 && $target->snapshot()['applyCount'] === 1, 'verified retry neither duplicates objects nor repeats confirmed apply');
    $objects->update(static function (array &$state): void { unset($state['objects']['ringgroup:600']); });
    invariant($executor->execute($exported, true)['state'] === 'configuration_verified'
        && $target->snapshot()['applyCount'] === 2, 'recreating disappeared saved configuration invalidates old apply evidence');
    foreach ($values as $value) { invariant(!str_contains(json_encode($result), $value) && !str_contains(json_encode($exported), $value), 'blueprint and checkpoint contain no resolved secret'); }
    foreach (['missing', 'incompatible'] as $case) {
        [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario($case);
        if ($case === 'missing') { unset($faultTarget->modules['core']); } else { $faultTarget->modules['core'] = '17.99.0'; }
        $failure = $faultExecutor->execute($blueprint, true);
        invariant($failure['error']['code'] === 'MODULE_VERSION_MISMATCH' && empty($faultTarget->snapshot()['objects']), $case . ' module blocks every mutation');
    }
    [$faultTarget, $faultStore, $faultJournal] = $scenario('secret');
    $failure = (new BuildExecutor($faultTarget, $faultJournal, new MemorySecrets([])))->execute($blueprint, true);
    invariant($failure['state'] === 'failed' && empty($faultTarget->snapshot()['objects']), 'unresolved credentials block the complete plan before user creation');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('response');
    $faultTarget->loseResponse = 'pjsip:1001';
    $failure = $faultExecutor->execute($blueprint, true);
    invariant($failure['state'] === 'unknown' && isset($faultTarget->snapshot()['objects']['pjsip:1001']), 'accepted device mutation with lost response remains unknown, not failed creation');
    $faultTarget->loseResponse = null;
    invariant($faultExecutor->execute($blueprint, true)['state'] === 'configuration_verified'
        && count($faultTarget->snapshot()['creationOrder']) === 6, 'retry reconciles accepted device rather than blindly recreating it');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('apply');
    $faultTarget->failApply = true;
    $failure = $faultExecutor->execute($blueprint, true);
    invariant($failure['savedConfigurationVerified'] && $failure['apply']['state'] === 'unknown'
        && !$failure['callsVerified'], 'apply failure retains saved objects but cannot assert active or call-ready configuration');
    $faultTarget->failApply = false;
    invariant($faultExecutor->execute($blueprint, true)['state'] === 'configuration_verified'
        && count($faultTarget->snapshot()['creationOrder']) === 6, 'apply retry never duplicates saved objects');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('verify');
    $faultTarget->badReadback = true;
    $failure = $faultExecutor->execute($blueprint, true);
    invariant($failure['state'] === 'failed' && $failure['error']['stage'] === 'verification'
        && empty($faultTarget->snapshot()['applyCount']), 'read-back mismatch prevents both completion and apply');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('partial');
    $faultStore->update(static function (array &$state): void { $state['objects']['pjsip:1001'] = ['state' => 'partial']; });
    $failure = $faultExecutor->execute($blueprint, true);
    invariant($failure['state'] === 'failed' && empty($faultTarget->snapshot()['creationOrder']), 'partially created device blocks plan pending explicit reconciliation');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('equivalent');
    $firstUser = array_values(array_filter(BuildSpec::plan($blueprint), static fn (array $operation): bool => $operation['id'] === 'user:1001'))[0];
    $faultTarget->create($firstUser, $secrets, 'pbx-alpha');
    $failure = $faultExecutor->execute($blueprint, true);
    invariant($failure['operations']['user:1001']['ownership'] === 'pre_existing_preserved', 'equivalent pre-existing user is verified without claiming ownership');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('conflict');
    $faultTarget->create($firstUser, $secrets, 'pbx-alpha');
    $faultStore->update(static function (array &$state): void { $state['objects']['user:1001']['parameters']['name'] = 'Foreign name'; });
    $failure = $faultExecutor->execute($blueprint, true);
    invariant($failure['state'] === 'failed' && count($faultTarget->snapshot()['creationOrder']) === 1, 'conflicting pre-existing user is never overwritten and no later object is created');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('owner');
    $faultJournal->update(static function (array &$state): void { $state['objectOwners']['target']['user:1001'] = 'another-build'; });
    invariant($faultExecutor->execute($blueprint, true)['state'] === 'failed' && empty($faultTarget->snapshot()['objects']), 'foreign reconstruction ownership blocks all writes');
    foreach (['duplicate','missing_dependency','cycle','destination','unsupported','coverage','secret_scope'] as $case) {
        $invalid = $blueprint;
        if ($case === 'duplicate') { $invalid['operations'][] = $invalid['operations'][0]; }
        if ($case === 'missing_dependency') { $invalid['operations'] = array_values(array_filter($invalid['operations'], static fn (array $operation): bool => $operation['id'] !== 'user:1001')); }
        foreach ($invalid['operations'] as &$operation) {
            if ($case === 'cycle' && $operation['id'] === 'ringgroup:601') {
                $operation['parameters']['destination'] = '600'; $operation['dependsOn'][] = 'ringgroup:600'; sort($operation['dependsOn']);
            }
            if ($case === 'destination' && $operation['id'] === 'ringgroup:600') { $operation['parameters']['destination'] = 'arbitrary,dialplan,1'; }
            if ($case === 'unsupported') { $operation['parameters']['shell'] = 'do-not-run'; }
            if ($case === 'secret_scope' && $operation['object']['type'] === 'pjsip') { $operation['secretRefs']['authPassword'] = 'secret:pbx-bravo:2001'; }
        }
        unset($operation);
        if ($case === 'coverage') { $invalid['coverage']['blockingGaps'] = ['credential unavailable']; }
        try { BuildSpec::plan($invalid); throw new LogicException('Invalid build accepted: ' . $case); }
        catch (RuntimeException $exception) { invariant(true, $case . ' is rejected before target mutation'); }
    }
    [$faultTarget, $faultStore, $faultJournal] = $scenario('redaction');
    $sensitive = array_values($values)[0];
    $failure = (new BuildExecutor($faultTarget, $faultJournal, $secrets,
        static function () use ($sensitive): void { throw new RuntimeException($sensitive); }))->execute($blueprint, true);
    invariant(!str_contains(json_encode($failure), $sensitive), 'raw adapter exception containing a credential is not checkpointed or displayed');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('restart');
    file_put_contents($root . '/restart/secrets.json', json_encode($values)); chmod($root . '/restart/secrets.json', 0600);
    $process = proc_open([PHP_BINARY, __FILE__, 'crash', $root . '/restart'], [], $pipes);
    invariant(proc_close($process) === 73, 'worker terminates after accepted mutation before succeeded checkpoint');
    $savedJournal = $faultJournal->update(static fn (array &$state): array => array_values($state['builds'])[0]);
    invariant($savedJournal['operations']['user:1001']['state'] === 'running'
        && isset($faultTarget->snapshot()['objects']['user:1001']), 'pre-mutation checkpoint and target object survive process death');
    invariant($faultExecutor->execute($blueprint, true)['state'] === 'configuration_verified'
        && count($faultTarget->snapshot()['creationOrder']) === 6, 'new executor reconciles process-death window without duplicate objects');
    [$faultTarget, $faultStore, $faultJournal, $faultExecutor] = $scenario('concurrent');
    $process = proc_open([PHP_BINARY, __FILE__, 'hold', $root . '/concurrent'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    invariant(trim(fgets($pipes[1])) === 'LOCKED', 'separate process holds target-wide build lock');
    try { $faultExecutor->execute($blueprint, true); throw new LogicException('Concurrent execution was permitted.'); }
    catch (RuntimeException $exception) { invariant($exception->getMessage() === 'TARGET_BUILD_BUSY', 'simultaneous reconstruction fails before any object mutation'); }
    fwrite($pipes[0], "release\n");
    foreach ($pipes as $pipe) { fclose($pipe); }
    invariant(proc_close($process) === 0 && empty($faultTarget->snapshot()['objects']), 'competing build preserves the clean target');
    echo "{$checks} reconstruction invariants passed; simulated targets only.\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}