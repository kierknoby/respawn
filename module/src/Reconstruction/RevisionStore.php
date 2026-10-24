<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class RevisionStore
{
    public function __construct(private readonly JsonStateStore $store, private readonly int $retentionLimit = 50)
    {
        if ($retentionLimit < 2 || $retentionLimit > 500) { throw new RuntimeException('REVISION_RETENTION_LIMIT_INVALID'); }
    }

    public function capture(array $blueprint, string $origin = 'manual_capture', ?array $publicationAcknowledgement = null,
        ?array $verification = null, bool $completeManagedScope = true, ?string $publicationConnectionId = null): array
    {
        Blueprint::verifyHash($blueprint);
        $operations = BuildSpec::plan($blueprint);
        if (!in_array($origin, ['manual_capture','configured_publication','pre_operation_checkpoint','automatic_reconciliation'], true)) {
            throw new RuntimeException('REVISION_CAPTURE_ORIGIN_INVALID');
        }
        $source = $blueprint['sourcePbxId'];
        if ($publicationAcknowledgement !== null) {
            self::assertPublicationAcknowledgement($source, $blueprint['contentHash'], $publicationAcknowledgement,
                $publicationConnectionId);
        }
        return $this->store->update(function (array &$state) use ($blueprint, $operations, $source, $origin,
            $publicationAcknowledgement, $verification, $completeManagedScope): array {
            $parent = $state['revisionHeads'][$source]['lastCapturedRevisionId'] ?? null;
            $sequence = (int) ($state['revisionSequences'][$source] ?? count($state['revisions'][$source] ?? [])) + 1;
            $state['revisionSequences'][$source] = $sequence;
            $revisionId = 'rev-' . bin2hex(random_bytes(16));
            $secretRefs = [];
            $objectIds = [];
            foreach ($operations as $operation) {
                $objectIds[] = $operation['id'];
                foreach ($operation['secretRefs'] as $reference) { $secretRefs[$reference] = true; }
            }
            sort($objectIds, SORT_STRING);
            $managedIds = $objectIds;
            foreach ($state['revisions'][$source] ?? [] as $previous) {
                foreach ($previous['managedScope']['objectIds'] ?? [] as $id) { $managedIds[] = $id; }
            }
            $managedIds = array_values(array_unique($managedIds));
            sort($managedIds, SORT_STRING);
            $secretReferenceList = array_keys($secretRefs);
            sort($secretReferenceList, SORT_STRING);
            $verificationBound = ($verification['state'] ?? '') === 'configuration_verified'
                && ($verification['blueprintHash'] ?? '') === $blueprint['contentHash']
                && ($verification['scope']['complete'] ?? false) === $completeManagedScope
                && ($verification['scope']['objectIds'] ?? []) === $managedIds;
            $revision = ['schema' => 'respawn.local-revision.v1', 'revisionId' => $revisionId,
                'sourcePbxId' => $source, 'blueprintSchemaVersion' => 2,
                'contentHash' => $blueprint['contentHash'], 'parentRevisionId' => $parent, 'sequence' => $sequence,
                'capturedAt' => gmdate(DATE_ATOM), 'captureOrigin' => $origin,
                'moduleVersions' => $blueprint['modules'], 'objects' => $objectIds,
                'managedScope' => ['complete' => $completeManagedScope, 'objectIds' => $managedIds],
                'coverage' => $blueprint['coverage'], 'secretReferences' => $secretReferenceList,
                'validation' => ['blueprintHash' => 'verified', 'schema' => 'respawn.reconstruction.v2',
                    'planner' => 'passed', 'validatedAt' => gmdate(DATE_ATOM)],
                'publicationAcknowledgement' => $publicationAcknowledgement,
                'verificationEvidence' => $verificationBound ? $verification : null,
                'classification' => $verificationBound ? 'verified_supported_configuration_scope' : 'captured_not_known_good',
                'blueprint' => $blueprint, 'pins' => []];
            $revision['recordHash'] = self::recordHash($revision);
            $state['revisions'][$source][$revisionId] = $revision;
            $state['revisionHeads'][$source]['lastCapturedRevisionId'] = $revisionId;
            if ($publicationAcknowledgement !== null) {
                $state['revisionHeads'][$source]['lastAcknowledgedRevisionId'] = $revisionId;
            }
            if ($verificationBound) {
                $state['revisionHeads'][$source]['lastVerifiedRevisionId'] = $revisionId;
                $state['verifiedRevisionEvidence'][$source][$revisionId] = $verification;
            }
            $this->pruneState($state, $source);
            if (count($state['revisions'][$source]) > ($state['revisionRetentionLimit'] ?? $this->retentionLimit)) {
                throw new RuntimeException('REVISION_RETENTION_BLOCKED_BY_PINNED_HISTORY');
            }
            return self::publicRevision($revision);
        });
    }

    public function captureConfiguration(array $capture, string $origin = 'manual_capture'): array
    {
        ConfigurationCapture::verifyHash($capture);
        if (!in_array($origin, ['manual_capture','automatic_reconciliation'], true)) {
            throw new RuntimeException('REVISION_CAPTURE_ORIGIN_INVALID');
        }
        $source = $capture['sourcePbxId'];
        $objectIds = [];
        $collectionsComplete = true;
        foreach ($capture['collections'] as $collectionName => $collection) {
            if (($collection['state'] ?? '') !== 'captured') { $collectionsComplete = false; }
            foreach ($collection['objects'] ?? [] as $object) {
                $objectIds[] = self::captureObjectId($collectionName, $object['id']);
                if (($object['state'] ?? '') !== 'captured') { $collectionsComplete = false; }
            }
        }
        sort($objectIds, SORT_STRING);
        $nonCredentialGaps = array_values(array_filter($capture['blockingGaps'],
            static fn (string $gap): bool => !str_starts_with($gap, 'CREDENTIALS:')));
        $configurationComplete = $collectionsComplete && $nonCredentialGaps === [];
        return $this->store->update(function (array &$state) use ($capture, $source, $origin, $objectIds, $configurationComplete): array {
            $parent = $state['revisionHeads'][$source]['lastCapturedRevisionId'] ?? null;
            if (is_string($parent)) {
                $previous = $state['revisions'][$source][$parent] ?? null;
                if (is_array($previous) && ($previous['captureKind'] ?? '') === 'configuration_capture') {
                    self::validate($previous, $source, $parent);
                }
                if (is_array($previous) && ($previous['captureKind'] ?? '') === 'configuration_capture'
                    && ($previous['contentHash'] ?? '') === $capture['contentHash']) {
                    return self::publicRevision($previous) + ['captureResult'=>'unchanged'];
                }
            }
            $sequence = (int) ($state['revisionSequences'][$source] ?? count($state['revisions'][$source] ?? [])) + 1;
            $revisionId = 'rev-' . bin2hex(random_bytes(16));
            $managedIds = $objectIds;
            foreach ($state['revisions'][$source] ?? [] as $previous) {
                if (($previous['captureKind'] ?? '') !== 'configuration_capture') { continue; }
                foreach ($previous['managedScope']['objectIds'] ?? [] as $id) { $managedIds[] = $id; }
            }
            $managedIds = array_values(array_unique($managedIds));
            sort($managedIds, SORT_STRING);
            $revision = ['schema'=>'respawn.local-revision.v1','revisionId'=>$revisionId,'sourcePbxId'=>$source,
                'blueprintSchemaVersion'=>1,'captureKind'=>'configuration_capture','contentHash'=>$capture['contentHash'],
                'parentRevisionId'=>$parent,'sequence'=>$sequence,'capturedAt'=>gmdate(DATE_ATOM),
                'captureOrigin'=>$origin,'moduleVersions'=>$capture['modules'],'objects'=>$objectIds,
                'managedScope'=>['complete'=>$configurationComplete,'objectIds'=>$managedIds],
                'coverage'=>['state'=>$configurationComplete?'complete':'incomplete',
                    'blockingGaps'=>$capture['blockingGaps']],
                'secretReferences'=>$capture['credentials']['references'],
                'credentialStatus'=>$capture['credentials']['state'],'recoveryReady'=>false,
                'configurationCapture'=>$capture,'publicationAcknowledgement'=>null,
                'verificationEvidence'=>null,'classification'=>$configurationComplete
                    ? 'captured_configuration_recovery_not_verified' : 'incomplete_configuration_capture','pins'=>[]];
            $revision['recordHash'] = self::recordHash($revision);
            $state['revisions'][$source][$revisionId] = $revision;
            $state['revisionSequences'][$source] = $sequence;
            $state['revisionHeads'][$source]['lastCapturedRevisionId'] = $revisionId;
            if ($configurationComplete) { $state['revisionHeads'][$source]['lastCompleteConfigurationRevisionId'] = $revisionId; }
            $this->pruneState($state, $source);
            if (count($state['revisions'][$source]) > ($state['revisionRetentionLimit'] ?? $this->retentionLimit)) {
                throw new RuntimeException('REVISION_RETENTION_BLOCKED_BY_PINNED_HISTORY');
            }
            return self::publicRevision($revision) + ['captureResult'=>'captured'];
        });
    }

    public function markConfigurationCaptureRetained(string $source, string $revisionId,
        string $connectionId, array $acknowledgement): void
    {
        $revision = $this->get($source,$revisionId);
        if (($revision['captureKind'] ?? '') !== 'configuration_capture') { throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE'); }
        $captureHash = $revision['contentHash'];
        $expectedKey = hash('sha256',$connectionId . "\0" . $source . "\0" . $revisionId . "\0" . $captureHash);
        if ($connectionId === '' || ($acknowledgement['pbxId'] ?? '') !== $source
            || ($acknowledgement['connectionId'] ?? '') !== $connectionId
            || ($acknowledgement['revisionId'] ?? '') !== $revisionId
            || ($acknowledgement['captureId'] ?? '') !== $revisionId
            || ($acknowledgement['contentHash'] ?? '') !== $captureHash
            || ($acknowledgement['idempotencyKey'] ?? '') !== $expectedKey
            || !is_string($acknowledgement['retentionReceipt'] ?? null) || $acknowledgement['retentionReceipt'] === ''
            || !is_string($acknowledgement['publicationId'] ?? null) || $acknowledgement['publicationId'] === ''
            || !is_int($acknowledgement['version'] ?? null) || $acknowledgement['version'] < 1
            || ($acknowledgement['recoveryReady'] ?? null) !== false) {
            throw new RuntimeException('PUDDLE_DURABLE_ACK_INVALID');
        }
        $this->store->update(function (array &$state) use ($source,$revisionId,$connectionId,$acknowledgement): void {
            $revision = &$state['revisions'][$source][$revisionId];
            if (($revision['captureKind'] ?? '') !== 'configuration_capture') { throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE'); }
            if (isset($revision['retentionAcknowledgement'])
                && $revision['retentionAcknowledgement'] !== $acknowledgement) {
                throw new RuntimeException('PUDDLE_DURABLE_ACK_CONFLICT');
            }
            $revision['retentionConnectionId'] = $connectionId;
            $revision['retentionAcknowledgement'] = $acknowledgement;
            $revision['retentionState'] = 'durably_retained_unverified';
            $revision['recordHash'] = self::recordHash($revision);
        });
    }

    public function markConfigurationCaptureVerified(string $source, string $revisionId,
        string $connectionId, array $acknowledgement, array $retainedCapture): void
    {
        $revision = $this->get($source,$revisionId);
        if (($revision['captureKind'] ?? '') !== 'configuration_capture') { throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE'); }
        ConfigurationCapture::verifyHash($retainedCapture);
        if (($retainedCapture['sourcePbxId'] ?? '') !== $source
            || ($retainedCapture['contentHash'] ?? '') !== $revision['contentHash']
            || ($revision['retentionConnectionId'] ?? '') !== $connectionId
            || ($revision['retentionAcknowledgement'] ?? null) !== $acknowledgement
            || ($acknowledgement['connectionId'] ?? '') !== $connectionId
            || ($acknowledgement['revisionId'] ?? '') !== $revisionId
            || ($acknowledgement['contentHash'] ?? '') !== $revision['contentHash']) {
            throw new RuntimeException('PUDDLE_READBACK_MISMATCH');
        }
        $this->store->update(function (array &$state) use ($source,$revisionId,$connectionId,$acknowledgement): void {
            $revision = &$state['revisions'][$source][$revisionId];
            $revision['retentionState'] = 'independently_readback_verified';
            $revision['retentionVerifiedAt'] = gmdate(DATE_ATOM);
            $revision['retentionConnectionId'] = $connectionId;
            $revision['retentionAcknowledgement'] = $acknowledgement;
            $revision['recoveryReady'] = false;
            $revision['recordHash'] = self::recordHash($revision);
        });
    }

    public function list(string $source): array
    {
        return $this->store->inspect(static function (array $state) use ($source): array {
            $entries = [];
            foreach ($state['revisions'][$source] ?? [] as $revisionId => $revision) {
                try { self::validate($revision, $source, $revisionId); }
                catch (\Throwable) {
                    $entries[] = ['revisionId' => (string) $revisionId, 'state' => 'quarantined',
                        'actionable' => false, 'reasonCode' => 'REVISION_INTEGRITY_FAILED'];
                    continue;
                }
                $entries[] = self::publicRevision($revision);
            }
            usort($entries, static fn (array $left, array $right): int => ($right['sequence'] ?? 0) <=> ($left['sequence'] ?? 0)
                ?: strcmp($right['capturedAt'] ?? '', $left['capturedAt'] ?? ''));
            $heads = $state['revisionHeads'][$source] ?? [];
            foreach ($entries as &$entry) {
                $entry['isLastCaptured'] = ($entry['revisionId'] ?? '') === ($heads['lastCapturedRevisionId'] ?? null);
                $entry['isLastAcknowledged'] = ($entry['revisionId'] ?? '') === ($heads['lastAcknowledgedRevisionId'] ?? null);
                $entry['isLastApplied'] = ($entry['revisionId'] ?? '') === ($heads['lastAppliedRevisionId'] ?? null);
                $entry['isLastVerified'] = ($entry['revisionId'] ?? '') === ($heads['lastVerifiedRevisionId'] ?? null);
            }
            unset($entry);
            return $entries;
        });
    }

    public function get(string $source, string $revisionId): array
    {
        if (!preg_match('/^rev-[a-f0-9]{32}$/D', $revisionId)) { throw new RuntimeException('REVISION_ID_INVALID'); }
        $revision = $this->store->inspect(static fn (array $state): ?array => $state['revisions'][$source][$revisionId] ?? null);
        if ($revision === null) { throw new RuntimeException('REVISION_NOT_FOUND'); }
        self::validate($revision, $source, $revisionId);
        return $revision;
    }

    public function heads(string $source): array
    {
        return $this->store->inspect(static fn (array $state): array => $state['revisionHeads'][$source] ?? []);
    }

    public function retentionLimit(): int
    {
        return $this->store->inspect(fn (array $state): int => $state['revisionRetentionLimit'] ?? $this->retentionLimit);
    }

    public function compare(string $source, string $fromId, string $toId): array
    {
        $from = $this->get($source, $fromId);
        $to = $this->get($source, $toId);
        $left = self::revisionObjectMap($from);
        $right = self::revisionObjectMap($to);
        $changes = ['create' => [], 'update' => [], 'remove' => [], 'unchanged' => []];
        foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $id) {
            if (!isset($left[$id])) { $changes['create'][] = $id; }
            elseif (!isset($right[$id])) { $changes['remove'][] = $id; }
            elseif (DesiredStatePlanner::fingerprint($left[$id]) !== DesiredStatePlanner::fingerprint($right[$id])) {
                $changes['update'][] = $id;
            } else { $changes['unchanged'][] = $id; }
        }
        return ['sourcePbxId' => $source, 'fromRevisionId' => $fromId, 'toRevisionId' => $toId,
            'fromHash' => $from['contentHash'], 'toHash' => $to['contentHash'], 'changes' => $changes,
            'captureMetadataChanged' => ($from['coverage'] ?? null) !== ($to['coverage'] ?? null)
                || ($from['credentialStatus'] ?? null) !== ($to['credentialStatus'] ?? null)
                || ($from['moduleVersions'] ?? null) !== ($to['moduleVersions'] ?? null)
                || ($from['secretReferences'] ?? []) !== ($to['secretReferences'] ?? []),
            'providerReconciliationRequired' => $fromId !== $toId,
            'message' => 'Local configuration comparison does not reverse provider routing, billing or authority.'];
    }

    public static function operationCatalog(array $blueprint): array
    {
        $catalog = [];
        foreach (BuildSpec::plan($blueprint) as $operation) { $catalog[$operation['id']] = $operation; }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    public function operationCatalogForRevision(string $source, string $revisionId): array
    {
        $selected = $this->get($source, $revisionId);
        if (($selected['captureKind'] ?? '') === 'configuration_capture') {
            throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE');
        }
        $catalog = self::operationCatalog($selected['blueprint']);
        $history = $this->store->inspect(static function (array $state) use ($source): array {
            return array_values(array_reverse($state['revisions'][$source] ?? [], true));
        });
        foreach ($history as $revision) {
            try { self::validate($revision, $source, $revision['revisionId'] ?? ''); }
            catch (\Throwable) { continue; }
            if (($revision['captureKind'] ?? '') === 'configuration_capture') { continue; }
            foreach (BuildSpec::plan($revision['blueprint']) as $operation) {
                $catalog[$operation['id']] ??= $operation;
            }
        }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    public function markPublished(string $source, string $revisionId, array $acknowledgement, string $connectionId): void
    {
        $revision = $this->get($source, $revisionId);
        if (($revision['captureKind'] ?? '') === 'configuration_capture') { throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE'); }
        self::assertPublicationAcknowledgement($source, $revision['contentHash'], $acknowledgement, $connectionId);
        $this->store->update(function (array &$state) use ($source, $revisionId, $acknowledgement): void {
            $entry = &$state['revisions'][$source][$revisionId];
            $entry['publicationAcknowledgement'] = $acknowledgement;
            $entry['recordHash'] = self::recordHash($entry);
            $state['revisionHeads'][$source]['lastAcknowledgedRevisionId'] = $revisionId;
        });
    }

    private static function assertPublicationAcknowledgement(string $source, string $contentHash, array $acknowledgement,
        ?string $connectionId): void
    {
        if (($acknowledgement['pbxId'] ?? '') !== $source || !is_string($connectionId) || $connectionId === ''
            || !is_string($acknowledgement['connectionId'] ?? null) || $acknowledgement['connectionId'] !== $connectionId
            || !is_string($acknowledgement['blueprintId'] ?? null)
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $acknowledgement['blueprintId'])
            || ($acknowledgement['hash'] ?? '') !== $contentHash
            || !is_int($acknowledgement['version'] ?? null) || $acknowledgement['version'] < 1) {
            throw new RuntimeException('REVISION_PUBLICATION_ACK_INVALID');
        }
    }

    public function savePlan(string $source, array $plan): array
    {
        if (($plan['sourcePbxId'] ?? '') !== $source) { throw new RuntimeException('DESIRED_STATE_PLAN_BINDING_INVALID'); }
        DesiredStatePlanner::verify($plan);
        $this->store->update(function (array &$state) use ($source, $plan): void {
            $state['desiredPlans'][$source][$plan['planId']] = ['plan' => $plan,
                'createdAt' => gmdate(DATE_ATOM), 'expiresAt' => gmdate(DATE_ATOM, time() + 900), 'state' => 'previewed'];
        });
        return $plan;
    }

    public function getPlan(string $source, string $planId): array
    {
        $entry = $this->store->inspect(static fn (array $state): ?array => $state['desiredPlans'][$source][$planId] ?? null);
        $state = is_array($entry) ? ($entry['state'] ?? '') : '';
        if (!is_array($entry) || !in_array($state, ['previewed','running','unknown','failed','configuration_verified'], true)
            || ($state === 'previewed' && strtotime($entry['expiresAt'] ?? '') <= time())) {
            throw new RuntimeException('DESIRED_STATE_PLAN_EXPIRED_OR_MISSING');
        }
        DesiredStatePlanner::verify($entry['plan']);
        return $entry['plan'];
    }

    public function markPlanExecuted(string $source, string $planId, array $result): void
    {
        $this->store->update(function (array &$state) use ($source, $planId, $result): void {
            if (!isset($state['desiredPlans'][$source][$planId])) { throw new RuntimeException('DESIRED_STATE_PLAN_NOT_FOUND'); }
            $state['desiredPlans'][$source][$planId]['state'] = $result['state'] ?? 'unknown';
            $state['desiredPlans'][$source][$planId]['execution'] = $result;
            $state['desiredPlans'][$source][$planId]['executedAt'] = gmdate(DATE_ATOM);
        });
    }

    public function checkpointCurrentState(string $source, string $revisionId, array $scope, array $snapshot, string $planId): string
    {
        $revision = $this->get($source, $revisionId);
        if (($revision['captureKind'] ?? '') === 'configuration_capture') { throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE'); }
        $normalized = DesiredStatePlanner::normaliseSnapshot($snapshot);
        if (($scope['complete'] ?? false) !== true) { throw new RuntimeException('PRE_OPERATION_SCOPE_INCOMPLETE'); }
        $checkpointId = 'checkpoint-' . bin2hex(random_bytes(12));
        $checkpoint = ['schema' => 'respawn.pre-operation-checkpoint.v1', 'checkpointId' => $checkpointId,
            'sourcePbxId' => $source, 'revisionId' => $revisionId, 'planId' => $planId,
            'scope' => $scope, 'targetSnapshot' => $normalized,
            'targetSnapshotHash' => DesiredStatePlanner::fingerprint($normalized),
            'capturedAt' => gmdate(DATE_ATOM), 'classification' => 'recovery_information_not_rollback_guarantee'];
        $checkpoint['recordHash'] = self::recordHash($checkpoint);
        $this->store->update(function (array &$state) use ($source, $revisionId, $planId, $checkpointId, $checkpoint): void {
            $state['preOperationCheckpoints'][$source][$checkpointId] = $checkpoint;
            if (isset($state['desiredPlans'][$source][$planId])) {
                $state['desiredPlans'][$source][$planId]['preOperationCheckpointId'] = $checkpointId;
            }
            $revision = &$state['revisions'][$source][$revisionId];
            if (!isset($revision['pins'][$planId])) {
                $revision['pins'][$planId] = ['reason' => 'Pre-operation recovery checkpoint dependency', 'pinnedAt' => gmdate(DATE_ATOM)];
                $revision['recordHash'] = self::recordHash($revision);
            }
        });
        return $checkpointId;
    }

    public function markRespawnOperation(string $source, string $revisionId, string $operationId, string $operationState): void
    {
        $this->store->update(function (array &$state) use ($source, $revisionId, $operationId, $operationState): void {
            if (!isset($state['revisions'][$source][$revisionId])) { return; }
            $revision = &$state['revisions'][$source][$revisionId];
            if (in_array($operationState, ['accepted','unknown','provisioning','configuration','connectivity','routing_prepared','ready','active'], true)) {
                $revision['pins'][$operationId] = ['reason' => 'Respawn operation dependency', 'state' => $operationState, 'pinnedAt' => gmdate(DATE_ATOM)];
            } else { unset($revision['pins'][$operationId]); }
            $revision['recordHash'] = self::recordHash($revision);
            $this->pruneState($state, $source);
        });
    }

    public function pin(string $source, string $revisionId, string $operationId, string $reason): void
    {
        $this->store->update(function (array &$state) use ($source, $revisionId, $operationId, $reason): void {
            $revision = &$state['revisions'][$source][$revisionId];
            if (!isset($revision['revisionId'])) { throw new RuntimeException('REVISION_NOT_FOUND'); }
            $revision['pins'][$operationId] = ['reason' => $reason, 'pinnedAt' => gmdate(DATE_ATOM)];
            $revision['recordHash'] = self::recordHash($revision);
        });
    }

    public function unpin(string $source, string $revisionId, string $operationId): void
    {
        $this->store->update(function (array &$state) use ($source, $revisionId, $operationId): void {
            if (!isset($state['revisions'][$source][$revisionId])) { return; }
            unset($state['revisions'][$source][$revisionId]['pins'][$operationId]);
            $state['revisions'][$source][$revisionId]['recordHash'] = self::recordHash($state['revisions'][$source][$revisionId]);
            $this->pruneState($state, $source);
        });
    }

    public function markApplied(string $source, string $revisionId, array $scope, array $verification): void
    {
        $revision = $this->get($source, $revisionId);
        if (($revision['captureKind'] ?? '') === 'configuration_capture') { throw new RuntimeException('PUDDLE_CAPTURE_NOT_EXECUTABLE'); }
        $this->store->update(function (array &$state) use ($source, $revisionId, $scope, $verification): void {
            $state['revisionHeads'][$source]['lastAppliedRevisionId'] = $revisionId;
            $revision = &$state['revisions'][$source][$revisionId];
            $revision['lastApplyEvidence'] = ['scope' => $scope, 'state' => $verification['state'] ?? 'unknown',
                'appliedAt' => gmdate(DATE_ATOM)];
            if (($verification['state'] ?? '') === 'configuration_verified') {
                $state['revisionHeads'][$source]['lastVerifiedRevisionId'] = $revisionId;
                $evidence = ['scope' => $scope, 'verifiedAt' => gmdate(DATE_ATOM),
                    'claim' => 'supported configuration read-back only'];
                $state['verifiedRevisionEvidence'][$source][$revisionId] = $evidence;
                $revision['verificationEvidence'] = $evidence;
                $revision['classification'] = 'verified_supported_configuration_scope';
            }
            $revision['recordHash'] = self::recordHash($revision);
        });
    }

    public function setRetentionLimit(int $limit): void
    {
        if ($limit < 2 || $limit > 500) { throw new RuntimeException('REVISION_RETENTION_LIMIT_INVALID'); }
        $this->store->update(function (array &$state) use ($limit): void {
            $state['revisionRetentionLimit'] = $limit;
            foreach (array_keys($state['revisions'] ?? []) as $source) { $this->pruneState($state, $source); }
        });
    }

    private function pruneState(array &$state, string $source): void
    {
        $limit = $state['revisionRetentionLimit'] ?? $this->retentionLimit;
        $entries = $state['revisions'][$source] ?? [];
        if (count($entries) <= $limit) { return; }
        uasort($entries, static fn (array $left, array $right): int => ($left['sequence'] ?? 0) <=> ($right['sequence'] ?? 0)
            ?: strcmp($left['capturedAt'], $right['capturedAt']));
        $heads = $state['revisionHeads'][$source] ?? [];
        foreach ($entries as $revisionId => $revision) {
            if (count($state['revisions'][$source]) <= $limit) { break; }
            if (!empty($revision['pins']) || in_array($revisionId, $heads, true)) { continue; }
            unset($state['revisions'][$source][$revisionId]);
        }
    }

    private static function validate(array $revision, string $source, string $revisionId): void
    {
        if (($revision['schema'] ?? '') !== 'respawn.local-revision.v1' || ($revision['revisionId'] ?? '') !== $revisionId
            || ($revision['sourcePbxId'] ?? '') !== $source || !is_array($revision['managedScope'] ?? null)
            || !is_array($revision['pins'] ?? null)) { throw new RuntimeException('REVISION_SCHEMA_UNSUPPORTED'); }
        $expected = $revision['recordHash'] ?? '';
        if (!is_string($expected) || !hash_equals(self::recordHash($revision), $expected)) { throw new RuntimeException('REVISION_INTEGRITY_FAILED'); }
        if (($revision['captureKind'] ?? '') === 'configuration_capture') {
            if (($revision['blueprintSchemaVersion'] ?? 0) !== 1 || !is_array($revision['configurationCapture'] ?? null)) {
                throw new RuntimeException('REVISION_SCHEMA_UNSUPPORTED');
            }
            ConfigurationCapture::verifyHash($revision['configurationCapture']);
            if (($revision['contentHash'] ?? '') !== $revision['configurationCapture']['contentHash']) {
                throw new RuntimeException('REVISION_BLUEPRINT_HASH_MISMATCH');
            }
            return;
        }
        if (($revision['blueprintSchemaVersion'] ?? 0) !== 2 || !is_array($revision['blueprint'] ?? null)) {
            throw new RuntimeException('REVISION_SCHEMA_UNSUPPORTED');
        }
        Blueprint::verifyHash($revision['blueprint']);
        if (($revision['contentHash'] ?? '') !== $revision['blueprint']['contentHash']) { throw new RuntimeException('REVISION_BLUEPRINT_HASH_MISMATCH'); }
    }

    private static function recordHash(array $revision): string
    {
        unset($revision['recordHash']);
        return DesiredStatePlanner::fingerprint($revision);
    }

    private static function publicRevision(array $revision): array
    {
        $safe = $revision;
        unset($safe['blueprint'], $safe['configurationCapture'], $safe['pins'], $safe['recordHash']);
        $safe['pinned'] = !empty($revision['pins']);
        $safe['pinCount'] = count($revision['pins'] ?? []);
        return $safe;
    }

    private static function operationMap(array $blueprint): array
    {
        $map = [];
        foreach (BuildSpec::plan($blueprint) as $operation) {
            $id = $operation['object']['identity'];
            $map[$operation['id']] = ['id'=>$id,'type'=>$operation['adapter'],'state'=>'captured',
                'fields'=>$operation['parameters']];
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    private static function revisionObjectMap(array $revision): array
    {
        if (($revision['captureKind'] ?? '') !== 'configuration_capture') {
            return self::operationMap($revision['blueprint']);
        }
        $map = [];
        foreach ($revision['configurationCapture']['collections'] as $collectionName => $collection) {
            foreach ($collection['objects'] ?? [] as $object) {
                $map[self::captureObjectId($collectionName, $object['id'])] = [
                    'id'=>$object['id'],'type'=>$object['type'],'state'=>$object['state'],'fields'=>$object['fields']];
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    private static function captureObjectId(string $collectionName, string $objectId): string
    {
        $prefix = match ($collectionName) {
            'core.users' => 'user',
            'core.pjsip' => 'pjsip',
            'ringgroups' => 'ringgroup',
            default => $collectionName,
        };
        return $prefix . ':' . $objectId;
    }
}