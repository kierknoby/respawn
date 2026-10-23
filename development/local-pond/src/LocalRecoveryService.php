<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\DesiredStatePlanner;
use Respawn\Reconstruction\FileSecrets;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class LocalRecoveryService
{
    private string $targetId;
    private string $owner;
    private JsonStateStore $objects;
    private JsonStateStore $checkpoints;
    private JsonStateStore $operatorState;
    private RevisionStore $revisions;
    private FileSecrets $secrets;
    private SimulatedPbx $target;

    public function __construct(private readonly string $root, private readonly string $pbxId)
    {
        if (!in_array($pbxId, ['pbx-alpha','pbx-bravo'], true)) { throw new RuntimeException('LOCAL_PBX_ID_INVALID'); }
        $this->targetId = 'existing-' . $pbxId;
        $this->owner = 'local-managed:' . $pbxId;
        $targetRoot = $root . '/local-pbxs/' . $pbxId;
        $this->objects = new JsonStateStore($targetRoot . '/objects');
        $this->checkpoints = new JsonStateStore($targetRoot . '/checkpoints');
        $this->operatorState = new JsonStateStore($targetRoot . '/operator');
        $this->revisions = new RevisionStore(new JsonStateStore($root . '/local-revisions/' . $pbxId));
        $this->secrets = new FileSecrets($root . '/credentials/' . $pbxId . '.manifest.json');
        $this->target = new SimulatedPbx($this->objects, $this->targetId, $this->owner);
    }

    public static function seedAll(string $root): void
    {
        foreach (['pbx-alpha','pbx-bravo'] as $pbxId) { (new self($root, $pbxId))->seedFixtureIfEmpty(); }
    }

    public function ensureSeeded(): array
    {
        return $this->seedFixtureIfEmpty();
    }

    public function capture(string $origin = 'manual_capture'): array
    {
        $blueprint = $this->exportCurrent();
        return $this->revisions->capture($blueprint, $origin, null, $this->verifiedEvidence($blueprint));
    }

    public function revisionList(): array
    {
        return ['heads' => $this->revisions->heads($this->pbxId), 'revisions' => $this->revisions->list($this->pbxId),
            'target' => $this->targetSnapshot(), 'targetId' => $this->targetId,
            'providerReconciliationRequired' => $this->providerReconciliationRequired()];
    }

    public function sourceSnapshot(): array
    {
        $blueprint = $this->exportCurrent();
        $users = []; $devices = []; $groups = [];
        foreach (BuildSpec::plan($blueprint) as $operation) {
            $number = $operation['object']['identity'];
            if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
            elseif ($operation['object']['type'] === 'pjsip') {
                $devices[$number] = $operation['parameters'] + ['secretReference' => 'protected immutable source reference present'];
            } else { $groups[] = ['number' => $number] + $operation['parameters']; }
        }
        return ['pbxId' => $this->pbxId, 'sourceAdapter' => 'LOCAL_SIMULATED_EXISTING_PBX_READ_ONLY',
            'schema' => $blueprint['schema'], 'contentHash' => $blueprint['contentHash'],
            'modules' => $blueprint['modules'], 'coverage' => $blueprint['coverage'],
            'users' => $users, 'devices' => $devices, 'ringGroups' => $groups];
    }
        public function exportBlueprint(): array { return $this->exportCurrent(); }

    public function compare(string $fromId, string $toId): array
    {
        return $this->revisions->compare($this->pbxId, $fromId, $toId);
    }

    public function recordPublicationAcknowledgement(array $acknowledgement): array
    {
        if (($acknowledgement['pbxId'] ?? '') !== $this->pbxId) { throw new RuntimeException('REVISION_PUBLICATION_ACK_INVALID'); }
        foreach ($this->revisions->list($this->pbxId) as $entry) {
            if (($entry['contentHash'] ?? '') === ($acknowledgement['hash'] ?? '')) {
                $this->revisions->markPublished($this->pbxId, $entry['revisionId'], $acknowledgement);
                return $this->revisions->get($this->pbxId, $entry['revisionId']);
            }
        }
        $blueprint = $this->exportCurrent();
        if (($blueprint['contentHash'] ?? '') !== ($acknowledgement['hash'] ?? '')) {
            throw new RuntimeException('REVISION_PUBLICATION_CONTENT_NOT_CURRENT');
        }
        $revision = $this->revisions->capture($blueprint, 'configured_publication', $acknowledgement,
            $this->verifiedEvidence($blueprint));
        return $this->revisions->get($this->pbxId, $revision['revisionId']);
    }

    public function pinRespawnOperation(array $operation): void
    {
        $blueprintId = $operation['request']['blueprintId'] ?? null;
        $version = $operation['request']['blueprintVersion'] ?? null;
        if (!is_string($blueprintId) || !str_starts_with($blueprintId, 'bp-') || !is_int($version)) { return; }
        $hashPrefix = substr($blueprintId, 3);
        foreach ($this->revisions->list($this->pbxId) as $revision) {
            if (!str_starts_with($revision['contentHash'] ?? '', $hashPrefix)
                || (int) ($revision['publicationAcknowledgement']['version'] ?? 0) !== $version) { continue; }
            $this->revisions->pin($this->pbxId, $revision['revisionId'], $operation['operationId'],
                'Respawn operation ' . $version . ' dependency');
            $this->operatorState->update(static function (array &$state) use ($revision, $operation): void {
                $state['respawnRevisionPins'][$operation['operationId']] = $revision['revisionId'];
            });
            return;
        }
    }

    public function syncRespawnOperations(array $operations): void
    {
        $seen = [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || !is_string($operation['operationId'] ?? null)) { continue; }
            $id = $operation['operationId'];
            $seen[$id] = true;
            $this->pinRespawnOperation($operation);
            if (in_array($operation['state'] ?? '', ['torn_down','rolled_back'], true)) {
                $mapping = $this->operatorState->update(static fn (array &$state): ?string => $state['respawnRevisionPins'][$id] ?? null);
                if (is_string($mapping)) { $this->revisions->unpin($this->pbxId, $mapping, $id); }
                $this->operatorState->update(static function (array &$state) use ($id): void { unset($state['respawnRevisionPins'][$id]); });
            }
        }
        unset($seen);
    }

    public function plan(string $action, string $revisionId): array
    {
        if (!in_array($action, ['revert','regenerate'], true)) { throw new RuntimeException('LOCAL_ACTION_INVALID'); }
        $revision = $this->revisions->get($this->pbxId, $revisionId);
        $blueprint = $revision['blueprint'];
        $snapshot = $this->target->reconciliationSnapshot($this->secrets, $this->pbxId);
        $managed = $revision['managedScope'];
        $plan = DesiredStatePlanner::plan($blueprint, $snapshot, $managed, $this->targetId,
            $revisionId, $action, $this->target->reconciliationCapabilities(), $this->owner);
        $this->revisions->savePlan($this->pbxId, $plan);
        $this->revisions->pin($this->pbxId, $revisionId, $plan['planId'], 'Previewed local ' . ucfirst($action) . ' plan');
        return $plan;
    }

    public function execute(string $planId, string $confirmation): array
    {
        if (!preg_match('/^plan-[a-f0-9]{32}$/D', $planId) || $confirmation !== 'execute:' . $planId) {
            throw new RuntimeException('LOCAL_PLAN_CONFIRMATION_INVALID');
        }
        $plan = $this->revisions->getPlan($this->pbxId, $planId);
        $revision = $this->revisions->get($this->pbxId, $plan['revisionId']);
        if ($this->hasConflictingRespawnOperation()) { throw new RuntimeException('PROVIDER_RECONCILIATION_REQUIRED'); }
        $result = (new BuildExecutor($this->target, $this->checkpoints, $this->secrets))->executeDesiredState(
            $revision['blueprint'], $plan, $planId, true);
        $this->revisions->markPlanExecuted($this->pbxId, $planId, $result);
        if (($result['state'] ?? '') === 'configuration_verified') {
            $this->revisions->markApplied($this->pbxId, $revision['revisionId'], $revision['managedScope'], $result);
            $this->revisions->unpin($this->pbxId, $revision['revisionId'], $planId);
        }
        return ['result' => $result, 'plan' => $plan, 'providerReconciliationRequired' => true,
            'serviceInterruptionMayOccur' => true,
            'providerImplications' => 'Local configuration changes do not reverse Pond routing, billing, connectivity or authority. Reconcile separately.'];
    }

    public function introduceSupportedDrift(): array
    {
        $snapshot = $this->target->snapshot();
        $record = $snapshot['objects']['pjsip:1001'] ?? null;
        if (!is_array($record) || ($record['owner'] ?? '') !== $this->owner || ($record['shared'] ?? true)) {
            throw new RuntimeException('LOCAL_DRIFT_TARGET_NOT_OWNED');
        }
        $this->objects->update(static function (array &$state): void {
            $state['objects']['pjsip:1001']['parameters']['settings']['allow'] = 'alaw';
            $state['objects']['pjsip:1001']['revision'] = ($state['objects']['pjsip:1001']['revision'] ?? 0) + 1;
            $state['simulatedAdminChanges'][] = ['objectId' => 'pjsip:1001', 'field' => 'settings.allow',
                'at' => gmdate(DATE_ATOM), 'actor' => 'synthetic local administrator'];
        });
        return ['state' => 'simulated_admin_change', 'objectId' => 'pjsip:1001', 'field' => 'settings.allow',
            'newValue' => 'alaw', 'mode' => 'SIMULATED'];
    }

    public function stageSupportedRevisionB(): array
    {
        $heads = $this->revisions->heads($this->pbxId);
        $baseId = $heads['lastCapturedRevisionId'] ?? null;
        if (!is_string($baseId)) { throw new RuntimeException('REVISION_CAPTURE_REQUIRED'); }
        $baseRevision = $this->revisions->get($this->pbxId, $baseId);
        $blueprint = $baseRevision['blueprint'];
        [$users, $devices, $groups] = self::parts($blueprint);
        $users['1001']['name'] = 'Alpha 1001 Operations';
        $devices['1001']['description'] = 'Alpha 1001 Operations';
        $devices['1001']['authUsername'] = 'Alpha-1001-Operations';
        unset($users['1002'], $devices['1002']);
        $groups = array_values(array_filter($groups, static fn (array $group): bool => $group['number'] !== '601'));
        foreach ($groups as &$group) {
            if ($group['number'] === '600') { $group['destination'] = 'hangup'; $group['members'] = ['1001']; }
        }
        unset($group);
        $changed = BuildSpec::create($this->pbxId, $blueprint['modules'], $users, $devices, $groups);
        $planResult = $this->applySampleDesired('regenerate', $changed, $baseRevision['managedScope'], 'sample-supported-edit-B');
        if (($planResult['result']['state'] ?? '') !== 'configuration_verified') { return $planResult; }
        $revision = $this->revisions->capture($changed, 'manual_capture', null, $planResult['result']);
        $this->revisions->markApplied($this->pbxId, $revision['revisionId'], $revision['managedScope'], $planResult['result']);
        $planResult['revision'] = $revision;
        $planResult['changeDescription'] = 'Core user and fixed PJSIP identity updated; fallback group, member device and user removed in dependant-safe order.';
        return $planResult;
    }

    public function latestRevisionBlueprint(): array
    {
        $heads = $this->revisions->heads($this->pbxId);
        $revisionId = $heads['lastCapturedRevisionId'] ?? null;
        if (!is_string($revisionId)) { throw new RuntimeException('REVISION_NOT_FOUND'); }
        return $this->revisions->get($this->pbxId, $revisionId)['blueprint'];
    }

    private function applySampleDesired(string $action, array $blueprint, array $managedScope, string $revisionId): array
    {
        $snapshot = $this->target->reconciliationSnapshot($this->secrets, $this->pbxId);
        $plan = DesiredStatePlanner::plan($blueprint, $snapshot, $managedScope, $this->targetId,
            $revisionId, $action, $this->target->reconciliationCapabilities(), $this->owner);
        $this->operatorState->update(static function (array &$state) use ($plan): void {
            $state['samplePlans'][$plan['planId']] = $plan;
        });
        $result = (new BuildExecutor($this->target, $this->checkpoints, $this->secrets))->executeDesiredState(
            $blueprint, $plan, $plan['planId'], true);
        if (($result['state'] ?? '') === 'configuration_verified') {
            $result['verification']['blueprintHash'] = $blueprint['contentHash'];
            $this->operatorState->update(static function (array &$state) use ($blueprint, $result): void {
                $state['verifiedBlueprints'][$blueprint['contentHash']] = $result['verification'];
            });
        }
        return ['result' => $result, 'plan' => $plan, 'mode' => 'SIMULATED'];
    }

    private function seedFixtureIfEmpty(): array
    {
        $existing = $this->target->snapshot();
        if (!empty($existing['objects'])) { return ['state' => 'already_seeded', 'targetId' => $this->targetId]; }
        $blueprint = (new SourceFixtureAdapter(localFixturePath(), $this->root . '/credentials/' . $this->pbxId . '.manifest.json'))
            ->export($this->pbxId);
        $result = (new BuildExecutor($this->target, $this->checkpoints, $this->secrets))->execute($blueprint, true);
        if (($result['state'] ?? '') !== 'configuration_verified') { throw new RuntimeException('LOCAL_SOURCE_FIXTURE_SEED_FAILED'); }
        $objectIds = array_column(BuildSpec::plan($blueprint), 'id');
        sort($objectIds, SORT_STRING);
        $evidence = ['state' => 'configuration_verified', 'blueprintHash' => $blueprint['contentHash'],
            'scope' => ['complete' => true, 'objectIds' => $objectIds], 'verifiedAt' => gmdate(DATE_ATOM),
            'targetMode' => 'SIMULATED', 'claim' => 'supported configuration read-back only'];
        $this->operatorState->update(static function (array &$state) use ($blueprint, $evidence): void {
            $state['fixtureSeed'] = ['blueprintHash' => $blueprint['contentHash'], 'state' => 'SIMULATED', 'at' => gmdate(DATE_ATOM)];
            $state['verifiedBlueprints'][$blueprint['contentHash']] = $evidence;
        });
        return ['state' => 'seeded_simulated_existing_pbx', 'targetId' => $this->targetId,
            'blueprintHash' => $blueprint['contentHash']];
    }

    private function exportCurrent(): array
    {
        $snapshot = $this->target->snapshot();
        $selection = [];
        foreach ($snapshot['objects'] ?? [] as $id => $entry) {
            if (($entry['owner'] ?? '') === $this->owner && !($entry['shared'] ?? true) && ($entry['state'] ?? '') === 'complete') {
                $selection[] = $id;
            }
        }
        if ($selection === []) { throw new RuntimeException('LOCAL_SUPPORTED_SCOPE_EMPTY'); }
        sort($selection, SORT_STRING);
        return $this->target->export($this->pbxId, $selection, $this->secrets);
    }

    private function targetSnapshot(): array
    {
        $snapshot = $this->target->reconciliationSnapshot($this->secrets, $this->pbxId);
        $visible = [];
        foreach ($snapshot as $id => $entry) {
            $entry['configuration'] = $entry['parameters'];
            unset($entry['parameters'], $entry['secretRefs'], $entry['secretMatches'], $entry['secretReferenceAvailable']);
            $visible[$id] = $entry;
        }
        return ['mode' => 'SIMULATED_EXISTING_PBX', 'objects' => $visible,
            'creationOrder' => $this->target->snapshot()['creationOrder'] ?? [],
            'applyCount' => $this->target->snapshot()['applyCount'] ?? 0];
    }

    private function verifiedEvidence(array $blueprint): ?array
    {
        foreach ($this->operatorState->update(static fn (array &$state): array => $state['verifiedBlueprints'] ?? []) as $hash => $evidence) {
            if ($hash === $blueprint['contentHash']) { return $evidence; }
        }
        return null;
    }

    private function hasConflictingRespawnOperation(): bool
    {
        $path = $this->root . '/pond-state/state.json';
        if (!is_file($path) || is_link($path)) { return false; }
        try { $state = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return true; }
        foreach ($state['providerConnections'] ?? [] as $connections) {
            foreach ($connections[$this->pbxId]['operations'] ?? [] as $operation) {
                if (!in_array($operation['state'] ?? '', ['torn_down','rolled_back'], true)) { return true; }
            }
        }
        foreach ($state['submissions'] ?? [] as $submission) {
            if (($submission['pbxId'] ?? '') === $this->pbxId
                && in_array($submission['state'] ?? '', ['submitted_outcome_unknown','launch_outcome_unknown'], true)) { return true; }
        }
        foreach ($state['routingModel'][$this->pbxId] ?? [] as $unused) { return true; }
        return false;
    }

    private function providerReconciliationRequired(): bool
    {
        $path = $this->root . '/pond-state/state.json';
        if (!is_file($path) || is_link($path)) { return false; }
        try { $state = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return true; }
        foreach ($state['providerConnections'] ?? [] as $connectionId => $connections) {
            $ack = $connections[$this->pbxId]['publication']['ack'] ?? null;
            if (!$ack) { continue; }
            $headId = $this->revisions->heads($this->pbxId)['lastCapturedRevisionId'] ?? null;
            if ($headId === null) { return true; }
            try { $revision = $this->revisions->get($this->pbxId, $headId); }
            catch (\Throwable) { return true; }
            if (($ack['hash'] ?? '') !== ($revision['contentHash'] ?? '')) { return true; }
        }
        return false;
    }

    private static function parts(array $blueprint): array
    {
        $users = []; $devices = []; $groups = [];
        foreach (BuildSpec::plan($blueprint) as $operation) {
            $number = $operation['object']['identity'];
            if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
            elseif ($operation['object']['type'] === 'pjsip') {
                $devices[$number] = $operation['parameters'] + ['secretRef' => $operation['secretRefs']['authPassword']];
            } else { $groups[] = ['number' => $number] + $operation['parameters']; }
        }
        return [$users,$devices,$groups];
    }
}