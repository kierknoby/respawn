<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Protection\ProtectionReconciler;
use Respawn\Provider\MockProvider;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function protectionAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}

function protectionVariant(array $blueprint, string $description): array
{
    $users = []; $devices = []; $groups = [];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        $type = $operation['object']['type'];
        $number = $operation['object']['identity'];
        if ($type === 'user') { $users[$number] = $operation['parameters']; }
        elseif ($type === 'pjsip') { $devices[$number] = $operation['parameters'] + ['secretRef' => $operation['secretRefs']['authPassword']]; }
        else {
            $group = ['number' => $number] + $operation['parameters'];
            if ($number === '600') { $group['description'] = $description; }
            $groups[] = $group;
        }
    }
    return BuildSpec::create($blueprint['sourcePbxId'], $blueprint['modules'], $users, $devices, $groups);
}

function protectionAddExtension(array $blueprint): array
{
    $users = []; $devices = []; $groups = [];
    $templateSettings = null;
    foreach (BuildSpec::plan($blueprint) as $operation) {
        $number = $operation['object']['identity'];
        if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
        elseif ($operation['object']['type'] === 'pjsip') {
            $devices[$number] = $operation['parameters'] + ['secretRef' => $operation['secretRefs']['authPassword']];
            if ($templateSettings === null) { $templateSettings = $operation['parameters']['settings']; }
        } else {
            $group = ['number' => $number] + $operation['parameters'];
            if ($number === '600') { $group['members'][] = '1003'; }
            $groups[] = $group;
        }
    }
    $users['1003'] = ['name' => 'Alpha 1003', 'voicemail' => 'novm'];
    $devices['1003'] = ['user' => '1003', 'description' => 'Alpha 1003', 'authUsername' => 'Alpha-1003',
        'settings' => $templateSettings, 'secretRef' => 'secret:pbx-alpha:1003:v1'];
    return BuildSpec::create($blueprint['sourcePbxId'], $blueprint['modules'], $users, $devices, $groups);
}

function protectionChangeCodec(array $blueprint): array
{
    $users = []; $devices = []; $groups = [];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        $number = $operation['object']['identity'];
        if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
        elseif ($operation['object']['type'] === 'pjsip') {
            $device = $operation['parameters'] + ['secretRef' => $operation['secretRefs']['authPassword']];
            if ($number === '1001') { $device['settings']['allow'] = 'ulaw'; }
            $devices[$number] = $device;
        } else { $groups[] = ['number' => $number] + $operation['parameters']; }
    }
    return BuildSpec::create($blueprint['sourcePbxId'], $blueprint['modules'], $users, $devices, $groups);
}

function protectionRotateCredentialReference(array $blueprint): array
{
    $users = []; $devices = []; $groups = [];
    foreach (BuildSpec::plan($blueprint) as $operation) {
        $number = $operation['object']['identity'];
        if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
        elseif ($operation['object']['type'] === 'pjsip') {
            $device = $operation['parameters'] + ['secretRef' => $operation['secretRefs']['authPassword']];
            if ($number === '1001') { $device['secretRef'] = 'secret:pbx-alpha:1001:v2'; }
            $devices[$number] = $device;
        } else { $groups[] = ['number' => $number] + $operation['parameters']; }
    }
    return BuildSpec::create($blueprint['sourcePbxId'], $blueprint['modules'], $users, $devices, $groups);
}

function protectionRemoveTree(string $path): void
{
    if (is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) { if (file_exists($path)) { unlink($path); } return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') { protectionRemoveTree($path . '/' . $entry); }
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/respawn-protection-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $crashBlueprint = SimulatedPbx::blueprint('pbx-alpha');
    $crashRevisions = new RevisionStore(new JsonStateStore($root . '/crash-revisions'));
    $crashRevision = $crashRevisions->capture($crashBlueprint, 'automatic_reconciliation');
    $crashCoordinator = new ProtectionReconciler(new JsonStateStore($root . '/crash-source'), $crashRevisions,
        new MockProvider(new JsonStateStore($root . '/crash-controller'), 'pbx-alpha'), 'pbx-alpha', 300);
    $crashRecovered = $crashCoordinator->reconcile(static fn (): array => $crashBlueprint, true, 900);
    protectionAssert($crashRecovered['latestLocalRevisionId'] === $crashRevision['revisionId']
        && count($crashRevisions->list('pbx-alpha')) === 1,
        'restart after revision capture reuses the matching latest head without duplicating it');

    $lostControllerState = new JsonStateStore($root . '/lost-controller');
    $lostController = new MockProvider($lostControllerState, 'pbx-alpha');
    $lostResponseProvider = new class($lostController) implements \Respawn\Provider\RetainedBlueprintProvider {
        private bool $loseResponse = true;
        public function __construct(private \Respawn\Provider\RetainedBlueprintProvider $delegate) {}
        public function connectionIdentity(): string { return $this->delegate->connectionIdentity(); }
        public function retainRevision(string $pbxId, array $blueprint, string $revisionId, string $idempotencyKey): array
        {
            $result = $this->delegate->retainRevision($pbxId, $blueprint, $revisionId, $idempotencyKey);
            if ($this->loseResponse) { $this->loseResponse = false; throw new RuntimeException('PROTECTION_RESPONSE_LOST'); }
            return $result;
        }
        public function retrieveRevision(string $pbxId, string $revisionId): array
        { return $this->delegate->retrieveRevision($pbxId, $revisionId); }
        public function retentionEvidenceMode(): string { return $this->delegate->retentionEvidenceMode(); }
    };
    $lostRevisions = new RevisionStore(new JsonStateStore($root . '/lost-revisions'));
    $lostState = new JsonStateStore($root . '/lost-source');
    $lostCoordinator = new ProtectionReconciler($lostState, $lostRevisions, $lostResponseProvider, 'pbx-alpha', 300);
    $lostFirst = $lostCoordinator->reconcile(static fn (): array => SimulatedPbx::blueprint('pbx-alpha'), true, 3000);
    protectionAssert($lostFirst['state'] === 'publication_failed' && $lostFirst['pendingRevisions'] === 1,
        'lost publication response leaves the exact local revision durably queued');
    $lostRetryCoordinator = new ProtectionReconciler($lostState, $lostRevisions, $lostController, 'pbx-alpha', 300);
    $lostRetry = $lostRetryCoordinator->reconcile(static fn (): array => SimulatedPbx::blueprint('pbx-alpha'), true, 3600);
    $retainedCount = $lostControllerState->update(static fn (array &$state): int =>
        count($state['retainedBlueprints'][$lostController->connectionIdentity()]['pbx-alpha'] ?? []));
    protectionAssert($lostRetry['latestVerifiedRevisionId'] === $lostFirst['latestLocalRevisionId']
        && $retainedCount === 1 && $lostRetry['pendingRevisions'] === 0,
        'restart retries committed publication idempotently and verifies the retained revision');

    $sourceState = new JsonStateStore($root . '/source');
    $controllerState = new JsonStateStore($root . '/independent-controller');
    $revisions = new RevisionStore(new JsonStateStore($root . '/source-revisions'), 10);
    $provider = new MockProvider($controllerState, 'pbx-alpha');
    $blueprint = SimulatedPbx::blueprint('pbx-alpha');
    $exporter = static function () use (&$blueprint): array { return $blueprint; };
    $coordinator = new ProtectionReconciler($sourceState, $revisions, $provider, 'pbx-alpha', 300);

    $first = $coordinator->reconcile($exporter, true, 1000);
    protectionAssert($first['latestLocalRevisionId'] !== null && $first['latestVerifiedRevisionId'] === $first['latestLocalRevisionId']
        && $first['latestProviderAcknowledgedRevisionId'] === $first['latestLocalRevisionId']
        && $first['latestAcknowledgedRevisionId'] === $first['latestLocalRevisionId']
        && $first['retentionEvidenceMode'] === 'SIMULATED' && $first['state'] === 'external_retention_unverified',
        'initial revision is captured and independently retrieved, but mock retention is not reported as production protection');
    $revisionA = $first['latestVerifiedRevisionId'];
    protectionAssert($first['schedulerState'] === 'recent' && $coordinator->status(true, 1400)['schedulerState'] === 'missed'
        && $coordinator->status(true, 1001)['lastInvocationCompletedAt'] === $first['lastInvocationCompletedAt'],
        'scheduler inactivity differs from healthy unchanged state and status reads never refresh its heartbeat');
    $firstHash = $first['latestObservedHash'];
    protectionAssert($coordinator->status(false, 1000)['state'] === 'not_configured'
        && $coordinator->status(true, 1400)['state'] === 'protection_stale',
        'status distinguishes disabled protection from an overdue scheduled reconciliation');

    $unchanged = $coordinator->reconcile($exporter, true, 1300);
    protectionAssert($unchanged['latestObservedHash'] === $firstHash && count($revisions->list('pbx-alpha')) === 1,
        'unchanged configuration creates no duplicate local revision or remote publication');

    $providerOffline = new MockProvider($controllerState, 'pbx-alpha', false);
    $coordinatorOffline = new ProtectionReconciler($sourceState, $revisions, $providerOffline, 'pbx-alpha', 300);
    $blueprint = protectionVariant($blueprint, 'Primary B');
    $failedB = $coordinatorOffline->reconcile($exporter, true, 1600);
    $revisionB = $failedB['latestLocalRevisionId'];
    $log = file_get_contents($root . '/source/protection.log');
    $events = array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
        array_filter(explode("\n", $log)));
    protectionAssert(end($events)['state'] === 'publication_failed' && end($events)['stage'] === 'publication'
        && !str_contains($log, 'secret:') && !str_contains($log, 'blueprint') && !str_contains($log, 'token'),
        'returned publication failures produce persistent useful diagnostics without credentials or protected payloads');
    protectionAssert($revisionB !== $revisionA && $failedB['latestVerifiedRevisionId'] === $revisionA
        && $failedB['latestProviderAcknowledgedRevisionId'] === $revisionA
        && $failedB['latestAcknowledgedRevisionId'] === $revisionA && $failedB['latestSubmittedRevisionId'] === $revisionB
        && $failedB['state'] === 'publication_failed' && $failedB['pendingRevisions'] === 1,
        'failed publication preserves the last verified revision and queues the exact newer revision');

    $blueprint = protectionVariant($blueprint, 'Primary C');
    $failedC = $coordinatorOffline->reconcile($exporter, true, 1900);
    $revisionC = $failedC['latestLocalRevisionId'];
    protectionAssert($revisionC !== $revisionB && $failedC['pendingRevisions'] === 2
        && $failedC['latestVerifiedRevisionId'] === $revisionA && $failedC['latestSubmittedRevisionId'] === $revisionB,
        'multiple changes during outage remain queued without claiming the latest state is protected');

    $coordinatorOnline = new ProtectionReconciler($sourceState, $revisions,
        new MockProvider($controllerState, 'pbx-alpha'), 'pbx-alpha', 300);
    $recoveredB = $coordinatorOnline->reconcile($exporter, true, 2200);
    protectionAssert($recoveredB['latestVerifiedRevisionId'] === $revisionB && $recoveredB['pendingRevisions'] === 1,
        'restarted coordinator retries the oldest queued revision before later changes');
    $recoveredC = $coordinatorOnline->reconcile($exporter, true, 2500);
    protectionAssert($recoveredC['lastRecoveryAt'] !== null && $recoveredC['lastSuccessfulReconciliationAt'] !== null,
        'successful recovery records completion and clears the active publication failure');
    protectionAssert($recoveredC['latestVerifiedRevisionId'] === $revisionC && $recoveredC['pendingRevisions'] === 0
        && $recoveredC['lastError'] === null,
        'queued revisions publish and verify in FIFO order after connectivity returns');

    $verificationDue = strtotime($recoveredC['nextRetentionVerificationAt']);
    $controllerConnection = $provider->connectionIdentity();
    $controllerState->update(static function (array &$state) use ($controllerConnection, $revisionC): void {
        unset($state['retainedBlueprints'][$controllerConnection]['pbx-alpha'][$revisionC]);
    });
    $missingRetention = $coordinatorOnline->reconcile($exporter, true, $verificationDue);
    protectionAssert(($missingRetention['lastRetentionVerification']['state'] ?? '') === 'failed'
        && $missingRetention['pendingRevisions'] === 1 && $missingRetention['state'] === 'changes_awaiting_publication',
        'scheduled independent retrieval detects missing retained data and queues republication');
    $repairedRetention = $coordinatorOnline->reconcile($exporter, true, $verificationDue + 300);
    protectionAssert($repairedRetention['latestVerifiedRevisionId'] === $revisionC && $repairedRetention['pendingRevisions'] === 0,
        'missing retained revision is restored and verified from the pinned local snapshot');

    $beforeExportFailure = $revisions->heads('pbx-alpha')['lastCapturedRevisionId'];
    $failedExport = $coordinatorOnline->reconcile(static function (): array { throw new RuntimeException('SOURCE_EXPORT_FAILED'); },
        true, $verificationDue + 600);
    protectionAssert($failedExport['state'] === 'configuration_export_failed'
        && ($revisions->heads('pbx-alpha')['lastCapturedRevisionId'] ?? '') === $beforeExportFailure,
        'failed export does not replace the last valid revision');

    $blueprint = SimulatedPbx::blueprint('pbx-alpha');
    $returnedToPriorHash = $coordinatorOnline->reconcile($exporter, true, $verificationDue + 900);
    protectionAssert($returnedToPriorHash['latestLocalRevisionId'] !== $revisionA
        && $returnedToPriorHash['latestObservedHash'] === $firstHash,
        'A-to-B-to-A configuration change creates a new chronological revision with the earlier content hash');
    for ($rotation=0; $rotation<5; $rotation++) {
        file_put_contents($root . '/source/protection.log',str_repeat('x',262144));
        $sourceState->protectionEvent(['state'=>'publication_failed','lastError'=>'SECRET_REFERENCE_UNRESOLVED']);
    }
    $logs = glob($root . '/source/protection.log*');
    protectionAssert(count($logs) === 4,
        'diagnostics retain the current log and three archives; observed files=' . count($logs));
    foreach ($logs as $logPath) {
        clearstatcache(true, $logPath);
        $logMode = fileperms($logPath) & 0777;
        protectionAssert(($logMode & 0077) === 0,
            'diagnostics remain private; file=' . basename($logPath) . ' observed mode=' . sprintf('%04o', $logMode));
    }
    $logEvent = json_decode(trim(file_get_contents($root . '/source/protection.log')), true, 8, JSON_THROW_ON_ERROR);
    protectionAssert(($logEvent['reason'] ?? null) === 'credentials_unavailable',
        'diagnostics use the safe credential-failure category; category matches=' .
        (($logEvent['reason'] ?? null) === 'credentials_unavailable' ? 'yes' : 'no'));

    $newProvider = new MockProvider($controllerState, 'pbx-alpha', true, null, 'mock-protection-v2:connection-change');
    $newConnectionCoordinator = new ProtectionReconciler($sourceState, $revisions, $newProvider, 'pbx-alpha', 300);
    $afterConnectionChange = $newConnectionCoordinator->reconcile($exporter, true, $verificationDue + 1200);
    protectionAssert($afterConnectionChange['retentionConnectionId'] === $newProvider->connectionIdentity()
        && $afterConnectionChange['latestVerifiedRevisionId'] === $afterConnectionChange['latestLocalRevisionId'],
        'provider connection change invalidates old retention evidence and verifies on the new connection');

    $priorRevision = $afterConnectionChange['latestLocalRevisionId'];
    $blueprint = protectionAddExtension($blueprint);
    $newExtension = $newConnectionCoordinator->reconcile($exporter, true, $verificationDue + 1500);
    protectionAssert($newExtension['latestLocalRevisionId'] !== $priorRevision
        && $newExtension['latestObservedHash'] !== $afterConnectionChange['latestObservedHash'],
        'adding a supported extension and device creates a changed protection revision');
    $priorRevision = $newExtension['latestLocalRevisionId'];
    $blueprint = protectionChangeCodec($blueprint);
    $changedPjsip = $newConnectionCoordinator->reconcile($exporter, true, $verificationDue + 1800);
    protectionAssert($changedPjsip['latestLocalRevisionId'] !== $priorRevision
        && $changedPjsip['latestObservedHash'] !== $newExtension['latestObservedHash'],
        'changing a supported PJSIP codec setting creates a changed protection revision');
    $priorRevision = $changedPjsip['latestLocalRevisionId'];
    $blueprint = protectionRotateCredentialReference($blueprint);
    $rotated = $newConnectionCoordinator->reconcile($exporter, true, $verificationDue + 2100);
    protectionAssert($rotated['latestLocalRevisionId'] !== $priorRevision
        && $rotated['latestObservedHash'] !== $changedPjsip['latestObservedHash']
        && $rotated['credentialsIndependentlyRecoverable'] === false,
        'credential-reference rotation creates a new hash but does not claim escrow readiness');

    protectionRemoveTree($root . '/source');
    protectionRemoveTree($root . '/source-revisions');
    $retrieved = $newProvider->retrieveRevision('pbx-alpha', $rotated['latestVerifiedRevisionId']);
    protectionAssert($retrieved['blueprint']['contentHash'] === $rotated['latestObservedHash']
        && $retrieved['revisionId'] === $rotated['latestLocalRevisionId']
        && $retrieved['blueprint']['sourcePbxId'] === 'pbx-alpha',
        'controller-side retained revision remains retrievable after source-side state is deleted');
    protectionAssert($repairedRetention['credentialsIndependentlyRecoverable'] === false
        && $repairedRetention['state'] === 'external_retention_unverified',
        'source-secret references are not misreported as independently recoverable credentials');
    echo "Protection lifecycle tests passed. Controller storage and credentials are synthetic; no off-site service or FreePBX was used.\n";
} finally { protectionRemoveTree($root); }
