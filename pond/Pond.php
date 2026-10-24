<?php
declare(strict_types=1);
namespace Respawn\Pond;

use PDO;
use Respawn\Reconstruction\Blueprint;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\ConfigurationCapture;
use RuntimeException;

final class Pond
{
    private PDO $database;
    private string $key;

    public function __construct(string $directory, string $keyFile)
    {
        self::privatePath($directory, true);
        self::privatePath($keyFile, false);
        $this->key = file_get_contents($keyFile);
        if (strlen($this->key) !== 32) { throw new RuntimeException('POND_KEY_INVALID'); }
        umask(0077);
        $databaseFile = $directory . '/pond.sqlite';
        if (!file_exists($databaseFile)) {
            $handle = fopen($databaseFile, 'x');
            if ($handle === false || !chmod($databaseFile, 0600)) { throw new RuntimeException('POND_DATABASE_CREATE_FAILED'); }
            fclose($handle);
        }
        clearstatcache(true, $databaseFile);
        self::privatePath($databaseFile, false);
        $this->database = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('PRAGMA journal_mode=DELETE; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS identity (id TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS principals (token TEXT PRIMARY KEY, tenant TEXT NOT NULL, pbx TEXT NOT NULL, connection TEXT NOT NULL, permissions TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS objects (scope TEXT NOT NULL, kind TEXT NOT NULL, id TEXT NOT NULL, body TEXT NOT NULL, PRIMARY KEY(scope,kind,id));
            CREATE TABLE IF NOT EXISTS requests (principal TEXT NOT NULL, id TEXT NOT NULL, expires INTEGER NOT NULL, PRIMARY KEY(principal,id));');
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            if ($this->database->query('SELECT COUNT(*) FROM identity')->fetchColumn() == 0) {
                $this->database->prepare('INSERT INTO identity VALUES (?)')->execute(['pond-' . bin2hex(random_bytes(16))]);
            }
            $this->database->exec('COMMIT');
        } catch (\Throwable $exception) { $this->database->exec('ROLLBACK'); throw $exception; }
    }

    public static function health(string $directory, string $keyFile): array
    {
        self::privatePath($directory, true);
        self::privatePath($keyFile, false);
        $key = file_get_contents($keyFile);
        if (!is_string($key) || strlen($key) !== 32) { throw new RuntimeException('POND_KEY_INVALID'); }
        $databaseFile = $directory . '/pond.sqlite';
        self::privatePath($databaseFile, false);
        $database = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000;');
        $integrity = $database->query('PRAGMA quick_check')->fetchColumn();
        $identity = $database->query('SELECT id FROM identity LIMIT 1')->fetchColumn();
        $principalCount = (int) $database->query('SELECT COUNT(*) FROM principals')->fetchColumn();
        $revisionCount = 0;
        $credentialCount = 0;
        $keyConsistent = true;
        $lastRetentionAt = null;
        foreach ($database->query('SELECT kind, body FROM objects') as $row) {
            $body = json_decode($row['body'], true, 64, JSON_THROW_ON_ERROR);
            if ($row['kind'] === 'revision') {
                $revisionCount++;
                $at = $body['ack']['at'] ?? null;
                if (is_string($at) && ($lastRetentionAt === null || strcmp($at, $lastRetentionAt) > 0)) { $lastRetentionAt = $at; }
            } elseif ($row['kind'] === 'credential') {
                $credentialCount++;
                if (!is_string($body['keyId'] ?? null) || !hash_equals(substr(hash('sha256',$key),0,32), $body['keyId'])) {
                    $keyConsistent = false;
                }
            }
        }
        $headCount = 0;
        $recoverableHeadCount = 0;
        $revisionLookup = $database->prepare('SELECT body FROM objects WHERE scope=? AND kind=? AND id=?');
        foreach ($database->query("SELECT scope, body FROM objects WHERE kind='head' AND id='latest'") as $headRow) {
            $headCount++;
            $head = json_decode($headRow['body'], true, 32, JSON_THROW_ON_ERROR);
            $revisionLookup->execute([$headRow['scope'],'revision',$head['revisionId'] ?? '']);
            $revisionBody = $revisionLookup->fetchColumn();
            $revisionLookup->closeCursor();
            if (!is_string($revisionBody)) { continue; }
            $revision = json_decode($revisionBody, true, 64, JSON_THROW_ON_ERROR);
            $blueprint = $revision['blueprint'] ?? null;
            if (!is_array($blueprint)) { continue; }
            Blueprint::verifyHash($blueprint);
            $recoverable = true;
            foreach (BuildSpec::plan($blueprint) as $operation) {
                foreach ($operation['secretRefs'] ?? [] as $reference) {
                    $revisionLookup->execute([$headRow['scope'],'credential',$reference]);
                    $credentialBody = $revisionLookup->fetchColumn();
                    $revisionLookup->closeCursor();
                    $credential = is_string($credentialBody)
                        ? json_decode($credentialBody, true, 16, JSON_THROW_ON_ERROR) : null;
                    $nonce = is_array($credential) ? base64_decode($credential['nonce'] ?? '', true) : false;
                    $tag = is_array($credential) ? base64_decode($credential['tag'] ?? '', true) : false;
                    $ciphertext = is_array($credential) ? base64_decode($credential['ciphertext'] ?? '', true) : false;
                    if (!is_array($credential) || ($credential['keyId'] ?? '') !== substr(hash('sha256',$key),0,32)
                        || !is_string($nonce) || !is_string($tag) || !is_string($ciphertext)
                        || openssl_decrypt($ciphertext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,
                            $headRow['scope'] . "\0" . $reference) === false) {
                        $recoverable = false;
                    }
                }
            }
            if ($recoverable) { $recoverableHeadCount++; }
        }
        $database = null;
        return ['state'=>$integrity === 'ok' && $keyConsistent ? 'healthy' : 'degraded',
            'pondId'=>is_string($identity) ? $identity : null,
            'storageHealth'=>$integrity === 'ok' ? 'healthy' : 'degraded',
            'credentialKeyHealth'=>$keyConsistent ? 'consistent' : 'mismatch',
            'principalCount'=>$principalCount,'retainedRevisionCount'=>$revisionCount,
            'encryptedCredentialCount'=>$credentialCount,'lastSuccessfulRetentionAt'=>$lastRetentionAt,
            'recoveryHeadCount'=>$headCount,'recoverableHeadCount'=>$recoverableHeadCount,
            'recoveryMaterialAvailable'=>$integrity === 'ok' && $keyConsistent
                && $headCount > 0 && $recoverableHeadCount === $headCount,
            'readOnly'=>true];
    }

    public static function privatePath(string $path, bool $directory): void
    {
        if (!str_starts_with($path, '/') || realpath($path) !== $path || is_link($path)
            || ($directory ? !is_dir($path) : !is_file($path)) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('POND_PATH_UNSAFE');
        }
        for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
            $permissions = fileperms($parent);
            if (($permissions & 0022) !== 0 && !(($permissions & 01000) !== 0 && fileowner($parent) === 0)) {
                throw new RuntimeException('POND_PATH_UNSAFE');
            }
        }
    }

    public function enrol(string $token, string $tenant, string $pbx, string $connection, array $permissions): void
    {
        foreach ([$tenant, $pbx, $connection] as $identity) {
            if (!preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $identity)) { throw new RuntimeException('POND_IDENTITY_INVALID'); }
        }
        if (strlen($token) < 32 || array_diff($permissions, ['retain','read','escrow','recover']) || $permissions === []) {
            throw new RuntimeException('POND_ENROLMENT_INVALID');
        }
        $this->database->prepare('INSERT INTO principals VALUES (?,?,?,?,?)')->execute([
            hash('sha256', $token), $tenant, $pbx, $connection, json_encode(array_values($permissions), JSON_THROW_ON_ERROR)]);
    }

    public function revoke(string $token): void
    {
        $this->database->prepare('DELETE FROM principals WHERE token=?')->execute([hash('sha256', $token)]);
    }

    public function dispatch(string $token, array $request): array
    {
        $principalHash = hash('sha256', $token);
        $query = $this->database->prepare('SELECT * FROM principals WHERE token=?');
        $query->execute([$principalHash]);
        $principal = $query->fetch(PDO::FETCH_ASSOC);
        if (!$principal) { throw new RuntimeException('POND_UNAUTHORISED'); }
        $method = $request['method'] ?? '';
        $permission = ['retainRevision'=>'retain', 'retrieveRevision'=>'read', 'latestRevision'=>'read',
            'retainConfigurationCapture'=>'retain', 'retrieveConfigurationCapture'=>'read',
            'escrowCredential'=>'escrow', 'retrieveCredential'=>'recover', 'capabilities'=>'read', 'status'=>'read'][$method] ?? null;
        if ($permission === null || !in_array($permission, json_decode($principal['permissions'], true), true)
            || ($request['schema'] ?? '') !== 'respawn.provider-rpc.v2'
            || ($request['pbxId'] ?? '') !== $principal['pbx'] || ($request['connectionId'] ?? '') !== $principal['connection']) {
            throw new RuntimeException('POND_SCOPE_DENIED');
        }
        $requestId = $request['requestId'] ?? '';
        $timestamp = strtotime($request['sentAt'] ?? '');
        if (!is_string($requestId) || !preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $requestId)
            || $timestamp === false || abs(time() - $timestamp) > 60 || !is_array($request['parameters'] ?? null)) {
            throw new RuntimeException('POND_REQUEST_INVALID');
        }
        $scope = hash('sha256', $principal['tenant'] . "\0" . $principal['pbx']);
        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $this->database->prepare('DELETE FROM requests WHERE expires < ?')->execute([time()]);
            $this->database->prepare('INSERT INTO requests VALUES (?,?,?)')->execute([$principalHash, $requestId, time() + 120]);
            $result = $this->execute($method, $request['parameters'], $principal, $scope);
            $this->database->exec('COMMIT');
            return $result;
        } catch (\Throwable $exception) {
            $this->database->exec('ROLLBACK');
            if ($exception instanceof \PDOException) { throw new RuntimeException('POND_TRANSACTION_REJECTED'); }
            throw $exception;
        }
    }

    private function execute(string $method, array $params, array $principal, string $scope): array
    {
        $pbx = $principal['pbx'];
        $connection = $principal['connection'];
        if (isset($params['pbxId']) && $params['pbxId'] !== $pbx
            || isset($params['sourcePbxId']) && $params['sourcePbxId'] !== $pbx) { throw new RuntimeException('POND_SCOPE_DENIED'); }
        if ($method === 'capabilities') { return ['identity'=>$pbx, 'authorised'=>true, 'providerId'=>$this->database->query('SELECT id FROM identity')->fetchColumn(), 'capabilities'=>['retention']]; }
        if ($method === 'status') {
            $head = $this->get($scope, 'head', 'latest');
            $retained = is_array($head) ? $this->get($scope, 'revision', $head['revisionId'] ?? '') : null;
            $credentialsVerified = $retained !== null;
            $credentialCount = 0;
            foreach ($retained['blueprint']['operations'] ?? [] as $operation) {
                foreach ($operation['secretRefs'] ?? [] as $reference) {
                    $credentialCount++;
                    $credential = $this->get($scope, 'credential', $reference);
                    if ($credential === null) { $credentialsVerified = false; continue; }
                    try { $this->decrypt($credential, $scope . "\0" . $reference); }
                    catch (\Throwable) { $credentialsVerified = false; }
                }
            }
            $pondId = $this->database->query('SELECT id FROM identity')->fetchColumn();
            $storageHealth = $this->database->query('PRAGMA quick_check')->fetchColumn();
            return ['operations'=>[], 'authorised'=>true, 'enrolmentState'=>'authorised',
                'pondId'=>$pondId, 'storageHealth'=>$storageHealth === 'ok' ? 'healthy' : 'degraded',
                'lastReceivedRevisionId'=>$retained['ack']['revisionId'] ?? null,
                'lastSuccessfulRetentionAt'=>$retained['ack']['at'] ?? null,
                'configurationHash'=>$retained['blueprint']['contentHash'] ?? null,
                'recoveryInformationAvailable'=>$credentialsVerified,
                'retainedCredentialReferencesVerified'=>$credentialsVerified,
                'retainedCredentialReferenceCount'=>$credentialCount];
        }
        if (in_array($method, ['escrowCredential','retrieveCredential'], true)) {
            $reference = $params['secretReference'] ?? '';
            if (!is_string($reference) || !preg_match('/^secret:' . preg_quote($pbx, '/') . ':[0-9]{2,8}:[a-zA-Z0-9._-]{1,64}$/D', $reference)) { throw new RuntimeException('POND_REFERENCE_INVALID'); }
            $associated = $scope . "\0" . $reference;
            $stored = $this->get($scope, 'credential', $reference);
            if ($method === 'escrowCredential') {
                $secret = $params['secret'] ?? '';
                if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 128
                    || !preg_match('/^[a-zA-Z0-9._~!@#$%^&*+=:-]+$/D', $secret)
                    || !preg_match('/^[a-f0-9]{64}$/D', $params['idempotencyKey'] ?? '')) { throw new RuntimeException('POND_CREDENTIAL_INVALID'); }
                if ($stored !== null && !hash_equals($this->decrypt($stored, $associated), $secret)) { throw new RuntimeException('POND_IMMUTABLE_CONFLICT'); }
                if ($stored === null) {
                    $nonce = random_bytes(12);
                    $tag = '';
                    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $associated);
                    if ($ciphertext === false) { throw new RuntimeException('POND_ENCRYPTION_FAILED'); }
                    $stored = ['ciphertext'=>base64_encode($ciphertext), 'nonce'=>base64_encode($nonce), 'tag'=>base64_encode($tag),
                        'keyId'=>substr(hash('sha256', $this->key), 0, 32), 'vaultReceipt'=>'vault-' . bin2hex(random_bytes(16))];
                    $this->put($scope, 'credential', $reference, $stored);
                }
            }
            if ($stored === null) { throw new RuntimeException('POND_NOT_FOUND'); }
            $result = ['state'=>$method === 'escrowCredential' ? 'durably_escrowed' : 'retrieved',
                'sourcePbxId'=>$pbx, 'authenticatedPbxId'=>$pbx, 'connectionId'=>$connection, 'secretReference'=>$reference,
                'encryptedAtRest'=>true, 'keyId'=>$stored['keyId'], 'vaultReceipt'=>$stored['vaultReceipt']];
            if ($method === 'retrieveCredential') { $result['secret'] = $this->decrypt($stored, $associated); }
            else { $result['idempotencyKey'] = $params['idempotencyKey']; }
            return $result;
        }
        if ($method === 'retainConfigurationCapture') {
            $revisionId = $params['revisionId'] ?? '';
            $capture = $params['payload'] ?? null;
            if (!is_string($revisionId) || !preg_match('/^rev-[a-f0-9]{32}$/D', $revisionId)
                || !is_array($capture) || ($params['pbxId'] ?? '') !== $pbx) {
                throw new RuntimeException('PUDDLE_RETENTION_BINDING_INVALID');
            }
            ConfigurationCapture::verifyHash($capture);
            $hash = $capture['contentHash'];
            $idempotencyKey = hash('sha256', $connection . "\0" . $pbx . "\0" . $revisionId . "\0" . $hash);
            if (($capture['sourcePbxId'] ?? '') !== $pbx || ($params['contentHash'] ?? '') !== $hash
                || ($params['idempotencyKey'] ?? '') !== $idempotencyKey
                || ($capture['credentials']['recoveryReady'] ?? true) !== false) {
                throw new RuntimeException('PUDDLE_RETENTION_BINDING_INVALID');
            }
            $stored = $this->get($scope, 'configurationCapture', $revisionId);
            if ($stored !== null && (($stored['capture']['contentHash'] ?? '') !== $hash
                || ($stored['ack']['connectionId'] ?? '') !== $connection
                || ($stored['ack']['idempotencyKey'] ?? '') !== $idempotencyKey)) {
                throw new RuntimeException('PUDDLE_CAPTURE_IMMUTABLE_CONFLICT');
            }
            if ($stored === null) {
                $version = (int) ($this->get($scope, 'configurationCaptureCounter', 'accepted')['version'] ?? 0) + 1;
                $ack = ['pbxId'=>$pbx,'connectionId'=>$connection,'revisionId'=>$revisionId,'captureId'=>$revisionId,
                    'contentHash'=>$hash,'idempotencyKey'=>$idempotencyKey,
                    'retentionReceipt'=>'capture-receipt-' . bin2hex(random_bytes(16)),
                    'publicationId'=>'capture-publication-' . bin2hex(random_bytes(16)),
                    'version'=>$version,'at'=>gmdate(DATE_ATOM),'recoveryReady'=>false];
                $stored = ['capture'=>$capture,'ack'=>$ack];
                $this->put($scope, 'configurationCapture', $revisionId, $stored);
                $this->put($scope, 'configurationCaptureCounter', 'accepted', ['version'=>$version], true);
            }
            return ['state'=>'durably_retained_capture','ack'=>$stored['ack'],
                'credentialStatus'=>$capture['credentials']['state'],'recoveryReady'=>false];
        }
        if ($method === 'retrieveConfigurationCapture') {
            $revisionId = $params['revisionId'] ?? '';
            if (!is_string($revisionId) || !preg_match('/^rev-[a-f0-9]{32}$/D', $revisionId)
                || ($params['pbxId'] ?? '') !== $pbx) { throw new RuntimeException('PUDDLE_RETRIEVAL_BINDING_INVALID'); }
            $stored = $this->get($scope, 'configurationCapture', $revisionId);
            if ($stored === null) { throw new RuntimeException('PUDDLE_RETAINED_CAPTURE_NOT_FOUND'); }
            ConfigurationCapture::verifyHash($stored['capture']);
            if (($stored['capture']['sourcePbxId'] ?? '') !== $pbx
                || ($stored['ack']['connectionId'] ?? '') !== $connection
                || ($stored['ack']['revisionId'] ?? '') !== $revisionId
                || ($stored['ack']['contentHash'] ?? '') !== $stored['capture']['contentHash']) {
                throw new RuntimeException('PUDDLE_RETRIEVAL_BINDING_INVALID');
            }
            return ['state'=>'retained_configuration_capture','pbxId'=>$pbx,'connectionId'=>$connection,
                'revisionId'=>$revisionId,'ack'=>$stored['ack'],'capture'=>$stored['capture'],
                'recoveryReady'=>false];
        }
        if ($method === 'latestRevision') {
            $head = $this->get($scope, 'head', 'latest');
            if ($head === null) { throw new RuntimeException('POND_NOT_FOUND'); }
            $params['revisionId'] = $head['revisionId'];
        }
        $revisionId = $params['revisionId'] ?? '';
        if (!is_string($revisionId) || !preg_match('/^rev-[a-f0-9]{32}$/D', $revisionId)) { throw new RuntimeException('POND_REVISION_INVALID'); }
        $stored = $this->get($scope, 'revision', $revisionId);
        if ($method === 'retainRevision') {
            $blueprint = $params['payload'] ?? [];
            Blueprint::verifyHash($blueprint);
            BuildSpec::plan($blueprint);
            $hash = $blueprint['contentHash'];
            $key = hash('sha256', $connection . "\0" . $pbx . "\0" . $revisionId . "\0" . $hash);
            if (($blueprint['sourcePbxId'] ?? '') !== $pbx || ($params['contentHash'] ?? '') !== $hash
                || ($params['idempotencyKey'] ?? '') !== $key) { throw new RuntimeException('POND_PUBLICATION_INVALID'); }
            foreach ($blueprint['operations'] as $operation) {
                foreach ($operation['secretRefs'] ?? [] as $reference) {
                    $credential = $this->get($scope, 'credential', $reference);
                    if ($credential === null) { throw new RuntimeException('POND_CREDENTIAL_MISSING'); }
                    $this->decrypt($credential, $scope . "\0" . $reference);
                }
            }
            if ($stored !== null && ($stored['blueprint']['contentHash'] !== $hash || $stored['ack']['connectionId'] !== $connection)) { throw new RuntimeException('POND_IMMUTABLE_CONFLICT'); }
            $head = $this->get($scope, 'head', 'latest');
            $ordering = $params['ordering'] ?? null;
            if ($ordering !== null && (!is_array($ordering) || array_keys($ordering) !== ['parentRevisionId']
                || ($ordering['parentRevisionId'] !== null && (!is_string($ordering['parentRevisionId'])
                    || !preg_match('/^rev-[a-f0-9]{32}$/D', $ordering['parentRevisionId']))))) {
                throw new RuntimeException('POND_LINEAGE_INVALID');
            }
            if ($stored !== null && $ordering !== null && isset($stored['ordering']) && $stored['ordering'] !== $ordering) {
                throw new RuntimeException('POND_IMMUTABLE_CONFLICT');
            }
            if ($stored === null) {
                $version = (int) ($this->get($scope, 'counter', 'accepted')['version'] ?? $head['version'] ?? 0) + 1;
                $ack = ['pbxId'=>$pbx, 'connectionId'=>$connection, 'revisionId'=>$revisionId, 'hash'=>$hash,
                    'blueprintId'=>'bp-' . $hash, 'publicationId'=>'pub-' . bin2hex(random_bytes(16)),
                    'idempotencyKey'=>$key, 'retentionReceipt'=>'receipt-' . bin2hex(random_bytes(16)), 'version'=>$version, 'at'=>gmdate(DATE_ATOM)];
                $stored = ['blueprint'=>$blueprint, 'ack'=>$ack, 'ordering'=>$ordering];
                $this->put($scope, 'revision', $revisionId, $stored);
                $this->put($scope, 'counter', 'accepted', ['version'=>$version], true);
                if ($ordering !== null && $ordering['parentRevisionId'] === ($head['revisionId'] ?? null)) {
                    $head = ['revisionId'=>$revisionId, 'version'=>$version];
                    $this->put($scope, 'head', 'latest', $head, true);
                }
            }
            return ['state'=>'durably_retained', 'ack'=>$stored['ack'], 'recoveryHeadRevisionId'=>$head['revisionId'] ?? null];
        }
        if ($stored === null) { throw new RuntimeException('PROTECTION_RETAINED_REVISION_NOT_FOUND'); }
        Blueprint::verifyHash($stored['blueprint']);
        return ['state'=>'retained', 'pbxId'=>$pbx, 'connectionId'=>$connection, 'revisionId'=>$revisionId,
            'publicationId'=>$stored['ack']['publicationId'], 'blueprint'=>$stored['blueprint']];
    }

    private function decrypt(array $stored, string $associated): string
    {
        $secret = openssl_decrypt(base64_decode($stored['ciphertext'], true), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            base64_decode($stored['nonce'], true), base64_decode($stored['tag'], true), $associated);
        if ($secret === false) { throw new RuntimeException('POND_DECRYPTION_FAILED'); }
        return $secret;
    }

    private function get(string $scope, string $kind, string $id): ?array
    {
        $query = $this->database->prepare('SELECT body FROM objects WHERE scope=? AND kind=? AND id=?');
        $query->execute([$scope, $kind, $id]);
        $body = $query->fetchColumn();
        return $body === false ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }

    private function put(string $scope, string $kind, string $id, array $body, bool $replace = false): void
    {
        $this->database->prepare(($replace ? 'INSERT OR REPLACE' : 'INSERT') . ' INTO objects VALUES (?,?,?,?)')
            ->execute([$scope, $kind, $id, json_encode($body, JSON_THROW_ON_ERROR)]);
    }
}