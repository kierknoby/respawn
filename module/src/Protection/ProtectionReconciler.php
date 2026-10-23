<?php
declare(strict_types=1);
namespace Respawn\Protection;

use Respawn\Provider\RetainedBlueprintProvider;
use Respawn\Provider\RecoverableCredentialVault;
use Respawn\Provider\OrderedRetainedBlueprintProvider;
use Respawn\Reconstruction\SecretResolver;
use Respawn\Reconstruction\Blueprint;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class ProtectionReconciler
{
    public function __construct(private JsonStateStore $state, private RevisionStore $revisions,
        private RetainedBlueprintProvider $provider, private string $sourcePbxId, private int $intervalSeconds = 300,
        private ?SecretResolver $secrets = null)
    {
        if ($intervalSeconds < 60 || $intervalSeconds > 86400) { throw new RuntimeException('PROTECTION_INTERVAL_INVALID'); }
    }

    public function reconcile(callable $exporter, bool $enabled, ?int $now = null): array
    {
        $now ??= time();
        try {
            $status = $this->state->exclusive('protection:' . $this->sourcePbxId,
                fn (): array => $this->runLocked($exporter, $enabled, $now));
            $this->state->protectionEvent($status);
            return $status;
        } catch (\Throwable $exception) {
            try { $this->state->protectionEvent(['state'=>'reconciliation_failed']); }
            catch (\Throwable) { error_log('Respawn protection diagnostic storage failed; inspect protected storage and scheduler.'); }
            throw $exception;
        }
    }

    public function status(bool $enabled, ?int $now = null): array
    {
        $record = $this->readRecord();
        return self::formatStatus($record, $enabled, $now);
    }

    public function authoriseResume(string $revisionId): void
    {
        if (!$this->provider instanceof OrderedRetainedBlueprintProvider) { throw new RuntimeException('PROTECTION_ORDERING_UNAVAILABLE'); }
        $this->state->exclusive('protection:' . $this->sourcePbxId, function () use ($revisionId): void {
            $record = $this->readRecord();
            foreach ($record['outbox'] ?? [] as $item) {
                if (($item['state'] ?? '') !== 'verified') { throw new RuntimeException('PROTECTION_PENDING_RECONCILIATION_REQUIRED'); }
            }
            $head = $this->provider->retrieveLatestRevision($this->sourcePbxId);
            if (($head['revisionId'] ?? '') !== $revisionId) { throw new RuntimeException('PROTECTION_HEAD_LINEAGE_CONFLICT'); }
            $record['publicationParentRevisionId'] = $revisionId;
            $record['resumeFromLocalRevisionId'] = $this->revisions->heads($this->sourcePbxId)['lastCapturedRevisionId'] ?? null;
            $record['latestVerifiedRevisionId'] = null;
            $record['latestAcknowledgedRevisionId'] = null;
            unset($record['credentialVerification']);
            $record['lastRetentionVerification'] = ['state'=>'resume_pending'];
            $record['nextCheckAt'] = 0;
            $record['resumeAuthorisedAt'] = gmdate(DATE_ATOM);
            $this->writeRecord($record);
        });
    }

    private function runLocked(callable $exporter, bool $enabled, int $now): array
    {
        $record = $this->readRecord();
        $hadFailure = isset($record['lastError']) || isset($record['lastExportError']);
        $record['lastInvocationStartedAt'] = gmdate(DATE_ATOM, $now);
        $this->writeRecord($record);
        $connectionId = $this->provider->connectionIdentity();
        if (isset($record['retentionConnectionId']) && $record['retentionConnectionId'] !== $connectionId) {
            $record['previousRetentionConnectionId'] = $record['retentionConnectionId'];
            $record['retentionConnectionId'] = $connectionId;
            $record['latestProviderAcknowledgedRevisionId'] = null;
            $record['latestAcknowledgedRevisionId'] = null;
            $record['latestVerifiedRevisionId'] = null;
            $record['retentionEvidenceMode'] = null;
            unset($record['publicationParentRevisionId']);
            unset($record['credentialVerification']);
            $record['lastRetentionVerification'] = ['state' => 'invalidated',
                'reasonCode' => 'PROTECTION_PROVIDER_CONNECTION_CHANGED', 'invalidatedAt' => gmdate(DATE_ATOM, $now)];
            foreach ($record['outbox'] ?? [] as $revisionId => $item) {
                if (($item['state'] ?? '') === 'verified') { continue; }
                $record['outbox'][$revisionId]['connectionId'] = $connectionId;
                $record['outbox'][$revisionId]['state'] = 'queued';
                $record['outbox'][$revisionId]['attempts'] = 0;
                $record['outbox'][$revisionId]['nextAttemptAt'] = $now;
                unset($record['outbox'][$revisionId]['acknowledgement'], $record['outbox'][$revisionId]['idempotencyKey'],
                    $record['outbox'][$revisionId]['parentRevisionId']);
            }
            $latestId = $record['latestLocalRevisionId'] ?? null;
            if (is_string($latestId) && isset($record['outbox'][$latestId])) {
                $record['outbox'][$latestId]['connectionId'] = $connectionId;
                $record['outbox'][$latestId]['state'] = 'queued';
                $record['outbox'][$latestId]['attempts'] = 0;
                $record['outbox'][$latestId]['nextAttemptAt'] = $now;
                unset($record['outbox'][$latestId]['acknowledgement'], $record['outbox'][$latestId]['idempotencyKey'],
                    $record['outbox'][$latestId]['parentRevisionId']);
            }
        }
        $record['retentionConnectionId'] = $connectionId;
        $record['enabled'] = $enabled;
        $record['intervalSeconds'] = $this->intervalSeconds;
        if (!$enabled) {
            $record['state'] = 'not_configured';
            $record['lastInvocationCompletedAt'] = gmdate(DATE_ATOM, $now);
            $record['updatedAt'] = gmdate(DATE_ATOM, $now);
            $this->writeRecord($record);
            return self::formatStatus($record, false, $now);
        }

        if (($record['nextCheckAt'] ?? 0) <= $now) {
            $record['lastCheckStartedAt'] = gmdate(DATE_ATOM, $now);
            try {
                $stage = 'inspection';
                $blueprint = $exporter();
                Blueprint::verifyHash($blueprint);
                BuildSpec::plan($blueprint);
                if (($blueprint['sourcePbxId'] ?? '') !== $this->sourcePbxId) {
                    throw new RuntimeException('PROTECTION_SOURCE_IDENTITY_MISMATCH');
                }
                $stage = 'revision_storage';
                $record = $this->observeBlueprint($record, $blueprint, $now);
                $record['lastSuccessfulInspectionAt'] = gmdate(DATE_ATOM, $now);
                unset($record['lastExportError']);
            } catch (\Throwable $exception) {
                $record['lastExportError'] = self::errorCode($exception->getMessage(), 'BLUEPRINT_EXPORT_FAILED');
                $record['failureStage'] = $stage;
                $record['state'] = 'configuration_export_failed';
            }
            $record['lastCheckCompletedAt'] = gmdate(DATE_ATOM, $now);
            $record['nextCheckAt'] = $now + $this->intervalSeconds;
        }

        $record = $this->processNextPublication($record, $now);
        $record = $this->verifyLatestRetention($record, $now);
        $pending = array_filter($record['outbox'] ?? [], static fn (array $item): bool => ($item['state'] ?? '') !== 'verified');
        if ($pending === [] && ($record['lastRetentionVerification']['state'] ?? '') === 'verified') {
            unset($record['lastError'], $record['lastErrorAt']);
        }
        if (empty($record['lastExportError'])) {
            $record['state'] = self::deriveState($record, $now);
        }
        $record['lastInvocationCompletedAt'] = gmdate(DATE_ATOM, $now);
        if (!isset($record['lastError']) && !isset($record['lastExportError']) && $pending === []
            && ($record['lastRetentionVerification']['state'] ?? '') === 'verified') {
            $record['lastSuccessfulReconciliationAt'] = gmdate(DATE_ATOM, $now);
            if ($hadFailure) { $record['lastRecoveryAt'] = gmdate(DATE_ATOM, $now); }
            unset($record['failureStage']);
        }
        $record['updatedAt'] = gmdate(DATE_ATOM, $now);
        $this->writeRecord($record);
        return self::formatStatus($record, true, $now);
    }

    private function observeBlueprint(array $record, array $blueprint, int $now): array
    {
        $hash = $blueprint['contentHash'];
        $previousHash = $record['latestObservedHash'] ?? null;
        $matching = null;
        if ($previousHash === $hash && is_string($record['latestLocalRevisionId'] ?? null)
            && $record['latestLocalRevisionId'] !== ($record['resumeFromLocalRevisionId'] ?? null)) {
            $current = $this->revisions->get($this->sourcePbxId, $record['latestLocalRevisionId']);
            if (($current['contentHash'] ?? '') !== $hash) { throw new RuntimeException('PROTECTION_LOCAL_HEAD_HASH_MISMATCH'); }
            $matching = ['revisionId' => $current['revisionId']];
        }
        if ($matching === null) {
            $lastCapturedId = $this->revisions->heads($this->sourcePbxId)['lastCapturedRevisionId'] ?? null;
            if (is_string($lastCapturedId)) {
                $lastCaptured = $this->revisions->get($this->sourcePbxId, $lastCapturedId);
                if (($lastCaptured['contentHash'] ?? '') === $hash && $lastCapturedId !== ($record['resumeFromLocalRevisionId'] ?? null)) {
                    $matching = ['revisionId' => $lastCapturedId];
                }
            }
        }
        if ($matching === null) { $matching = $this->revisions->capture($blueprint, 'automatic_reconciliation'); }
        $revisionId = $matching['revisionId'];
        unset($record['resumeFromLocalRevisionId']);
        $record['latestObservedHash'] = $hash;
        $record['latestLocalRevisionId'] = $revisionId;
        if ($previousHash !== $hash) { $record['lastDetectedChangeAt'] = gmdate(DATE_ATOM, $now); }
        if (!$this->isVerified($record, $revisionId, $hash)) {
            if (!isset($record['outbox'][$revisionId])) {
                $record['outboxSequence'] = (int) ($record['outboxSequence'] ?? 0) + 1;
                $record['outbox'][$revisionId] = ['revisionId' => $revisionId, 'contentHash' => $hash,
                    'connectionId' => $this->provider->connectionIdentity(), 'state' => 'queued', 'attempts' => 0,
                    'sequence' => $record['outboxSequence'],
                    'queuedAt' => gmdate(DATE_ATOM, $now), 'nextAttemptAt' => $now];
            }
            $this->revisions->pin($this->sourcePbxId, $revisionId, 'protection:' . $revisionId,
                'Pending independent blueprint retention verification');
        }
        return $record;
    }

    private function processNextPublication(array $record, int $now): array
    {
        $queue = $record['outbox'] ?? [];
        uasort($queue, static fn (array $left, array $right): int => ($left['sequence'] ?? 0) <=> ($right['sequence'] ?? 0));
        foreach ($queue as $revisionId => $item) {
            if (($item['state'] ?? '') === 'verified') { continue; }
            if (($item['nextAttemptAt'] ?? 0) > $now) { break; }
            try {
                $revision = $this->revisions->get($this->sourcePbxId, $revisionId);
                if (($revision['contentHash'] ?? '') !== ($item['contentHash'] ?? '')) {
                    throw new RuntimeException('PROTECTION_REVISION_HASH_MISMATCH');
                }
                $connectionId = $this->provider->connectionIdentity();
                $ack = null;
                if (($item['connectionId'] ?? '') === $connectionId) {
                    $ack = $item['acknowledgement'] ?? null;
                } else {
                    $record['outbox'][$revisionId]['connectionId'] = $connectionId;
                    unset($record['outbox'][$revisionId]['acknowledgement']);
                }
                $idempotencyKey = hash('sha256', $connectionId . "\0" . $this->sourcePbxId . "\0" . $revisionId . "\0" . $item['contentHash']);
                $record['outbox'][$revisionId]['idempotencyKey'] = $idempotencyKey;
                if (!is_array($ack)) {
                    $this->verifyCredentials($revision['blueprint'], true);
                    $record['outbox'][$revisionId]['state'] = 'publishing';
                    $record['outbox'][$revisionId]['attempts'] = (int) ($item['attempts'] ?? 0) + 1;
                    $record['outbox'][$revisionId]['lastAttemptAt'] = gmdate(DATE_ATOM, $now);
                    $record['outbox'][$revisionId]['idempotencyKey'] = $idempotencyKey;
                    $record['latestSubmittedRevisionId'] = $revisionId;
                    if ($this->provider instanceof OrderedRetainedBlueprintProvider
                        && !array_key_exists('parentRevisionId', $record['outbox'][$revisionId])) {
                        $record['outbox'][$revisionId]['parentRevisionId'] = $record['publicationParentRevisionId']
                            ?? $record['latestVerifiedRevisionId'] ?? null;
                    }
                    $this->writeRecord($record);
                    $result = $this->provider instanceof OrderedRetainedBlueprintProvider
                        ? $this->provider->retainOrderedRevision($this->sourcePbxId, $revision['blueprint'], $revisionId,
                            $idempotencyKey, $record['outbox'][$revisionId]['parentRevisionId'])
                        : $this->provider->retainRevision($this->sourcePbxId, $revision['blueprint'], $revisionId, $idempotencyKey);
                    $ack = $result['ack'] ?? null;
                    if (($result['state'] ?? '') !== 'durably_retained' || !is_array($ack)) {
                        throw new RuntimeException('PROTECTION_DURABLE_ACK_MISSING');
                    }
                    $this->validateAcknowledgement($ack, $revisionId, $item['contentHash']);
                    $record['lastSuccessfulPondCommunicationAt'] = gmdate(DATE_ATOM, $now);
                    $record['outbox'][$revisionId]['acknowledgement'] = $ack;
                    $record['outbox'][$revisionId]['state'] = 'acknowledgement_received';
                    $record['latestProviderAcknowledgedRevisionId'] = $revisionId;
                    $this->writeRecord($record);
                }
                $retained = $this->provider->retrieveRevision($this->sourcePbxId, $revisionId);
                if (($retained['pbxId'] ?? '') !== $this->sourcePbxId
                    || ($retained['revisionId'] ?? '') !== $revisionId
                    || ($retained['connectionId'] ?? '') !== ($ack['connectionId'] ?? '')
                    || ($retained['publicationId'] ?? '') !== ($ack['publicationId'] ?? '')
                    || !is_array($retained['blueprint'] ?? null)) {
                    throw new RuntimeException('PROTECTION_RETRIEVAL_BINDING_INVALID');
                }
                Blueprint::verifyHash($retained['blueprint']);
                if (($retained['blueprint']['contentHash'] ?? '') !== $item['contentHash']) {
                    throw new RuntimeException('PROTECTION_RETRIEVED_HASH_MISMATCH');
                }
                $this->validateAcknowledgement($ack, $revisionId, $item['contentHash']);
                $this->assertRecoveryHead($revisionId);
                $credentialReady = $this->verifyCredentials($revision['blueprint'], false);
                $record['credentialVerification'] = ['revisionId'=>$revisionId, 'verifiedAt'=>gmdate(DATE_ATOM, $now),
                    'state'=>$credentialReady ? 'verified' : 'unavailable'];
                $record['lastSuccessfulPondCommunicationAt'] = gmdate(DATE_ATOM, $now);
                $this->revisions->markPublished($this->sourcePbxId, $revisionId, $ack, $ack['connectionId']);
                $record['outbox'][$revisionId]['state'] = 'verified';
                $record['outbox'][$revisionId]['verifiedAt'] = gmdate(DATE_ATOM, $now);
                $record['latestAcknowledgedRevisionId'] = $revisionId;
                $record['latestVerifiedRevisionId'] = $revisionId;
                $record['publicationParentRevisionId'] = $revisionId;
                $record['retentionEvidenceMode'] = $this->provider->retentionEvidenceMode();
                $record['lastRetentionVerification'] = ['revisionId' => $revisionId,
                    'contentHash' => $item['contentHash'], 'verifiedAt' => gmdate(DATE_ATOM, $now), 'state' => 'verified'];
                $record['nextRetentionVerificationAt'] = $now + 86400;
                $this->revisions->unpin($this->sourcePbxId, $revisionId, 'protection:' . $revisionId);
                $this->pruneVerifiedOutbox($record);
            } catch (\Throwable $exception) {
                $attempts = max((int) ($item['attempts'] ?? 0), (int) ($record['outbox'][$revisionId]['attempts'] ?? 0));
                if (($record['outbox'][$revisionId]['state'] ?? '') !== 'publishing') { $attempts++; }
                $baseDelay = min(3600, 60 * (2 ** min(6, $attempts - 1)));
                $delay = min(3600, $baseDelay + random_int(0, min(30, intdiv($baseDelay, 4))));
                $errorCode = self::errorCode($exception->getMessage(), 'PROTECTION_PUBLICATION_FAILED');
                $record['outbox'][$revisionId]['attempts'] = $attempts;
                if (in_array($errorCode,
                    ['PROTECTION_RETAINED_REVISION_NOT_FOUND','PROTECTION_RETRIEVED_HASH_MISMATCH'], true)) {
                    unset($record['outbox'][$revisionId]['acknowledgement']);
                    $record['outbox'][$revisionId]['state'] = 'queued';
                } else {
                    $record['outbox'][$revisionId]['state'] = isset($record['outbox'][$revisionId]['acknowledgement'])
                        ? 'retention_unverified' : 'publication_failed';
                }
                $record['outbox'][$revisionId]['lastError'] = $errorCode;
                $record['outbox'][$revisionId]['nextAttemptAt'] = $now + $delay;
                $record['lastError'] = $record['outbox'][$revisionId]['lastError'];
                $record['failureStage'] = isset($record['outbox'][$revisionId]['acknowledgement']) ? 'retention' : 'publication';
                $record['lastErrorAt'] = gmdate(DATE_ATOM, $now);
            }
            break;
        }
        return $record;
    }

    private function pruneVerifiedOutbox(array &$record): void
    {
        $verified = array_filter($record['outbox'] ?? [], static fn (array $item): bool => ($item['state'] ?? '') === 'verified');
        if (count($verified) <= 50) { return; }
        uasort($verified, static fn (array $left, array $right): int => ($left['sequence'] ?? 0) <=> ($right['sequence'] ?? 0));
        while (count($verified) > 50) {
            $oldest = array_key_first($verified);
            if ($oldest === ($record['latestVerifiedRevisionId'] ?? null)) { break; }
            unset($record['outbox'][$oldest], $verified[$oldest]);
        }
    }

    private function verifyLatestRetention(array $record, int $now): array
    {
        $revisionId = $record['latestVerifiedRevisionId'] ?? null;
        if (!is_string($revisionId) || ($record['nextRetentionVerificationAt'] ?? PHP_INT_MAX) > $now) { return $record; }
        foreach ($record['outbox'] ?? [] as $item) {
            if (($item['state'] ?? '') !== 'verified') { return $record; }
        }
        try {
            $revision = $this->revisions->get($this->sourcePbxId, $revisionId);
            $ack = $revision['publicationAcknowledgement'] ?? [];
            $retained = $this->provider->retrieveRevision($this->sourcePbxId, $revisionId);
            if (($retained['pbxId'] ?? '') !== $this->sourcePbxId || ($retained['revisionId'] ?? '') !== $revisionId
                || ($retained['connectionId'] ?? '') !== ($ack['connectionId'] ?? '')
                || ($retained['publicationId'] ?? '') !== ($ack['publicationId'] ?? '')
                || !is_array($retained['blueprint'] ?? null)) {
                throw new RuntimeException('PROTECTION_RETRIEVAL_BINDING_INVALID');
            }
            Blueprint::verifyHash($retained['blueprint']);
            $this->assertRecoveryHead($revisionId);
            if (($retained['blueprint']['contentHash'] ?? '') !== ($revision['contentHash'] ?? '')) {
                throw new RuntimeException('PROTECTION_RETRIEVED_HASH_MISMATCH');
            }
            $record['lastRetentionVerification'] = ['revisionId' => $revisionId, 'contentHash' => $revision['contentHash'],
                'verifiedAt' => gmdate(DATE_ATOM, $now), 'state' => 'verified'];
            $credentialReady = $this->verifyCredentials($revision['blueprint'], false);
            $record['credentialVerification'] = ['revisionId'=>$revisionId, 'verifiedAt'=>gmdate(DATE_ATOM, $now),
                'state'=>$credentialReady ? 'verified' : 'unavailable'];
            $record['lastSuccessfulPondCommunicationAt'] = gmdate(DATE_ATOM, $now);
            $record['nextRetentionVerificationAt'] = $now + 86400;
        } catch (\Throwable $exception) {
            $code = self::errorCode($exception->getMessage(), 'PROTECTION_RETENTION_CHECK_FAILED');
            unset($record['credentialVerification']);
            $record['lastRetentionVerification'] = ['revisionId' => $revisionId, 'verifiedAt' => gmdate(DATE_ATOM, $now),
                'state' => 'failed', 'error' => $code];
            $record['lastError'] = $code;
            $record['lastErrorAt'] = gmdate(DATE_ATOM, $now);
            $record['nextRetentionVerificationAt'] = $now + min($this->intervalSeconds, 3600);
            if (in_array($code, ['PROTECTION_RETAINED_REVISION_NOT_FOUND','PROTECTION_RETRIEVED_HASH_MISMATCH'], true)) {
                $record['outbox'][$revisionId]['state'] = 'queued';
                $record['outbox'][$revisionId]['attempts'] = 0;
                $record['outbox'][$revisionId]['nextAttemptAt'] = $now;
                unset($record['outbox'][$revisionId]['acknowledgement']);
                $this->revisions->pin($this->sourcePbxId, $revisionId, 'protection:' . $revisionId,
                    'Controller retention verification failed; republication required');
            }
        }
        return $record;
    }

    private function assertRecoveryHead(string $revisionId): void
    {
        if ($this->provider instanceof OrderedRetainedBlueprintProvider
            && ($this->provider->retrieveLatestRevision($this->sourcePbxId)['revisionId'] ?? '') !== $revisionId) {
            throw new RuntimeException('PROTECTION_HEAD_LINEAGE_CONFLICT');
        }
    }

    private function verifyCredentials(array $blueprint, bool $escrow): bool
    {
        if ($this->secrets === null || !$this->provider instanceof RecoverableCredentialVault) { return false; }
        foreach (BuildSpec::plan($blueprint) as $operation) {
            foreach ($operation['secretRefs'] as $reference) {
                $secret = $this->secrets->resolve($reference, $this->sourcePbxId);
                if ($escrow) {
                    $this->provider->escrowCredential($this->sourcePbxId, $reference, $secret,
                        hash('sha256', $this->provider->connectionIdentity() . "\0" . $reference));
                }
                $result = $this->provider->retrieveCredential($this->sourcePbxId, $reference);
                if (($result['sourcePbxId'] ?? '') !== $this->sourcePbxId
                    || ($result['secretReference'] ?? '') !== $reference || !is_string($result['secret'] ?? null)
                    || !hash_equals($secret, $result['secret']) || ($result['encryptedAtRest'] ?? false) !== true) {
                    throw new RuntimeException('PROTECTION_CREDENTIAL_VERIFICATION_FAILED');
                }
            }
        }
        return true;
    }

    private function validateAcknowledgement(array $ack, string $revisionId, string $hash): void
    {
        if (($ack['pbxId'] ?? '') !== $this->sourcePbxId
            || ($ack['connectionId'] ?? '') !== $this->provider->connectionIdentity()
            || ($ack['revisionId'] ?? '') !== $revisionId
            || ($ack['hash'] ?? '') !== $hash || !is_string($ack['connectionId'] ?? null) || $ack['connectionId'] === ''
            || !is_string($ack['blueprintId'] ?? null) || $ack['blueprintId'] === ''
            || !is_string($ack['publicationId'] ?? null) || $ack['publicationId'] === ''
            || !is_string($ack['idempotencyKey'] ?? null)
            || !hash_equals($ack['idempotencyKey'], hash('sha256', $this->provider->connectionIdentity()
                . "\0" . $this->sourcePbxId . "\0" . $revisionId . "\0" . $hash))
            || !is_string($ack['retentionReceipt'] ?? null) || $ack['retentionReceipt'] === ''
            || !is_int($ack['version'] ?? null) || $ack['version'] < 1) {
            throw new RuntimeException('PROTECTION_ACKNOWLEDGEMENT_INVALID');
        }
    }

    private function isVerified(array $record, string $revisionId, string $hash): bool
    {
        return ($record['latestVerifiedRevisionId'] ?? '') === $revisionId
            && ($record['lastRetentionVerification']['contentHash'] ?? '') === $hash
            && ($record['lastRetentionVerification']['state'] ?? '') === 'verified';
    }

    private function readRecord(): array
    {
        $sourcePbxId = $this->sourcePbxId;
        return $this->state->update(static fn (array &$state): array => $state['protection'][$sourcePbxId] ?? []);
    }

    private function writeRecord(array $record): void
    {
        $this->state->update(function (array &$state) use ($record): void {
            $state['protection'][$this->sourcePbxId] = $record;
        });
    }

    public static function formatStatus(array $record, bool $enabled, ?int $now = null): array
    {
        $now ??= time();
        $record['enabled'] = $enabled;
        $completed = strtotime($record['lastInvocationCompletedAt'] ?? '') ?: 0;
        $started = strtotime($record['lastInvocationStartedAt'] ?? '') ?: 0;
        $scheduler = $completed === 0 ? 'never_observed' : ($now - $completed > 180 ? 'missed' : 'recent');
        if ($started > $completed && $now - $started > 120) { $scheduler = 'stalled'; }
        return ['enabled' => $enabled, 'state' => self::deriveState($record, $now),
            'schedulerState'=>$scheduler, 'lastInvocationStartedAt'=>$record['lastInvocationStartedAt'] ?? null,
            'lastInvocationCompletedAt'=>$record['lastInvocationCompletedAt'] ?? null,
            'lastSuccessfulReconciliationAt'=>$record['lastSuccessfulReconciliationAt'] ?? null,
            'lastRecoveryAt'=>$record['lastRecoveryAt'] ?? null, 'failureStage'=>$record['failureStage'] ?? null,
            'lastCheckStartedAt' => $record['lastCheckStartedAt'] ?? null,
            'lastCheckCompletedAt' => $record['lastCheckCompletedAt'] ?? null,
            'lastSuccessfulInspectionAt' => $record['lastSuccessfulInspectionAt'] ?? null,
            'lastSuccessfulPondCommunicationAt' => $record['lastSuccessfulPondCommunicationAt'] ?? null,
            'lastDetectedChangeAt' => $record['lastDetectedChangeAt'] ?? null,
            'latestObservedHash' => $record['latestObservedHash'] ?? null,
            'latestLocalRevisionId' => $record['latestLocalRevisionId'] ?? null,
            'latestSubmittedRevisionId' => $record['latestSubmittedRevisionId'] ?? null,
            'latestProviderAcknowledgedRevisionId' => $record['latestProviderAcknowledgedRevisionId'] ?? null,
            'retentionConnectionId' => $record['retentionConnectionId'] ?? null,
            'latestAcknowledgedRevisionId' => $record['latestAcknowledgedRevisionId'] ?? null,
            'latestVerifiedRevisionId' => $record['latestVerifiedRevisionId'] ?? null,
            'lastRetentionVerification' => $record['lastRetentionVerification'] ?? null,
            'nextRetentionVerificationAt' => isset($record['nextRetentionVerificationAt'])
                ? gmdate(DATE_ATOM, $record['nextRetentionVerificationAt']) : null,
            'retentionEvidenceMode' => $record['retentionEvidenceMode'] ?? null,
            'credentialsIndependentlyRecoverable' => ($record['credentialVerification']['state'] ?? '') === 'verified'
                && ($record['credentialVerification']['revisionId'] ?? '') === ($record['latestVerifiedRevisionId'] ?? '')
                && ($record['latestLocalRevisionId'] ?? '') === ($record['latestVerifiedRevisionId'] ?? '')
                && ($record['lastRetentionVerification']['state'] ?? '') === 'verified'
                && ($record['retentionEvidenceMode'] ?? '') === 'EXTERNAL_PROVIDER',
            'pendingRevisions' => count(array_filter($record['outbox'] ?? [], static fn (array $item): bool => ($item['state'] ?? '') !== 'verified')),
            'lastError' => $record['lastExportError'] ?? $record['lastError'] ?? null,
            'nextCheckAt' => isset($record['nextCheckAt']) ? gmdate(DATE_ATOM, $record['nextCheckAt']) : null,
            'intervalSeconds' => $record['intervalSeconds'] ?? null];
    }

    private static function deriveState(array $record, ?int $now = null): string
    {
        $now ??= time();
        if (empty($record['enabled'])) { return 'not_configured'; }
        if (isset($record['lastExportError'])) { return 'configuration_export_failed'; }
        if (empty($record['latestLocalRevisionId'])) { return 'initial_capture_pending'; }
        foreach ($record['outbox'] ?? [] as $item) {
            if (in_array($item['state'] ?? '', ['publication_failed'], true)) { return 'publication_failed'; }
            if (($item['state'] ?? '') === 'retention_unverified') { return 'external_retention_unverified'; }
            if (in_array($item['state'] ?? '', ['queued','publishing','acknowledgement_received'], true)) {
                return 'changes_awaiting_publication';
            }
        }
        if (isset($record['nextCheckAt']) && $record['nextCheckAt'] <= $now) { return 'protection_stale'; }
        if (($record['latestVerifiedRevisionId'] ?? '') !== ($record['latestLocalRevisionId'] ?? '')) {
            return 'external_retention_unverified';
        }
        if (($record['lastRetentionVerification']['state'] ?? '') !== 'verified') { return 'external_retention_unverified'; }
        if (($record['retentionEvidenceMode'] ?? '') !== 'EXTERNAL_PROVIDER') { return 'external_retention_unverified'; }
        if (($record['credentialVerification']['state'] ?? '') !== 'verified'
            || ($record['credentialVerification']['revisionId'] ?? '') !== ($record['latestLocalRevisionId'] ?? '')) {
            return 'credentials_unavailable';
        }
        return 'protected_and_current';
    }

    private static function errorCode(string $message, string $fallback): string
    {
        return preg_match('/^[A-Z0-9_]{3,64}$/D', $message) ? $message : $fallback;
    }
}