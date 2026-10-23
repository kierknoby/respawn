<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class BuildExecutor
{
    public function __construct(private BuildTarget $target, private JsonStateStore $store,
        private SecretResolver $secrets, private ?\Closure $observer = null) {}

    public function execute(array $blueprint, bool $authorised = false): array
    {
        if (!$authorised) { throw new RuntimeException('Explicit target reconstruction authorisation required.'); }
        Blueprint::verifyHash($blueprint);
        $operations = BuildSpec::plan($blueprint);
        $source = $blueprint['sourcePbxId'];
        $scope = $this->target->identity() . ':' . $source . ':' . $blueprint['contentHash'];
        return $this->store->exclusive($this->target->identity(), function () use ($blueprint, $operations, $source, $scope): array {
            $journal = $this->store->update(function (array &$state) use ($scope, $operations, $source, $blueprint): array {
                if (!isset($state['builds'][$scope])) {
                    $entries = [];
                    foreach ($operations as $operation) { $entries[$operation['id']] = ['state' => 'pending', 'ownership' => 'unclaimed']; }
                    $state['builds'][$scope] = ['buildId' => hash('sha256', $scope), 'sourcePbxId' => $source,
                        'targetId' => $this->target->identity(), 'blueprintHash' => $blueprint['contentHash'],
                        'state' => 'pending', 'operations' => $entries, 'apply' => ['state' => 'pending'],
                        'savedConfigurationVerified' => false, 'runtimeVerified' => false, 'callsVerified' => false];
                }
                return $state['builds'][$scope];
            });
            $stage = 'preflight';
            $currentId = null;
            $journal['state'] = 'preflight';
            $journal['savedConfigurationVerified'] = false;
            $this->checkpoint($scope, $journal);
            try {
                $environment = $this->target->environment();
                foreach ($blueprint['modules'] as $module => $version) {
                    if (($environment['modules'][$module] ?? '') !== $version) { throw new RuntimeException('MODULE_VERSION_MISMATCH'); }
                }
                foreach ($operations as $operation) {
                    if (array_diff($operation['requiredCapabilities'], $environment['capabilities'] ?? [])) { throw new RuntimeException('CAPABILITY_UNAVAILABLE'); }
                    foreach ($operation['secretRefs'] as $reference) { $this->secrets->resolve($reference, $source); }
                }
                $this->target->preflight($operations);
                foreach ($operations as $operation) {
                    $currentId = $operation['id'];
                    $this->assertOwnership($scope, $operation);
                    $this->compare($operation, $this->target->inspect($operation, $this->secrets, $source));
                }
                foreach ($operations as $operation) {
                    $currentId = $operation['id'];
                    $stage = 'inspect';
                    $current = $this->target->inspect($operation, $this->secrets, $source);
                    $this->compare($operation, $current);
                    if ($current['state'] === 'absent') {
                        $journal['state'] = 'running';
                        $journal['apply'] = ['state' => 'pending', 'reason' => 'saved_configuration_changed'];
                        $journal['operations'][$currentId] = ['state' => 'running', 'ownership' => 'claimed', 'at' => gmdate(DATE_ATOM)];
                        $this->checkpoint($scope, $journal, $operation);
                        $stage = 'mutation';
                        $this->target->create($operation, $this->secrets, $source);
                        if ($this->observer) { ($this->observer)('after_mutation', $currentId); }
                    }
                    $stage = 'verification';
                    $verified = $this->target->inspect($operation, $this->secrets, $source);
                    $this->compare($operation, $verified);
                    if ($verified['state'] !== 'present') { throw new RuntimeException('READBACK_MISSING'); }
                    $ownership = $journal['operations'][$currentId]['ownership'];
                    $journal['operations'][$currentId] = ['state' => 'succeeded',
                        'ownership' => $ownership === 'unclaimed' ? 'pre_existing_preserved' : 'owned', 'at' => gmdate(DATE_ATOM)];
                    $this->checkpoint($scope, $journal);
                }
                $journal['savedConfigurationVerified'] = true;
                $journal['state'] = 'saved_verified';
                $this->checkpoint($scope, $journal);
                $currentId = null;
                $stage = 'apply';
                if (($journal['apply']['state'] ?? '') !== 'succeeded') {
                    $journal['apply'] = ['state' => 'running', 'at' => gmdate(DATE_ATOM)];
                    $this->checkpoint($scope, $journal);
                    $receipt = $this->target->apply();
                    if (($receipt['generation'] ?? '') !== 'succeeded' || ($receipt['reload'] ?? '') !== 'succeeded') {
                        throw new RuntimeException('APPLY_NOT_CONFIRMED');
                    }
                    $journal['apply'] = ['state' => 'succeeded', 'receipt' => $receipt, 'at' => gmdate(DATE_ATOM)];
                    $this->checkpoint($scope, $journal);
                }
                $stage = 'post_apply_verification';
                foreach ($operations as $operation) {
                    $currentId = $operation['id'];
                    $actual = $this->target->inspect($operation, $this->secrets, $source);
                    $this->compare($operation, $actual);
                    if ($actual['state'] !== 'present') { throw new RuntimeException('POST_APPLY_MISSING'); }
                }
                $journal['state'] = 'configuration_verified';
                $journal['simulated'] = ($environment['mode'] ?? '') !== 'FREEPBX';
                $journal['targetMode'] = $environment['mode'] ?? 'unknown';
                unset($journal['error']);
                $this->checkpoint($scope, $journal);
                return $journal;
            } catch (\Throwable $exception) {
                $unknown = $stage === 'mutation' || ($stage === 'apply' && ($journal['apply']['state'] ?? '') === 'running');
                $journal['state'] = $unknown ? 'unknown' : 'failed';
                if ($currentId !== null) { $journal['operations'][$currentId]['state'] = $unknown ? 'unknown' : 'failed'; }
                if ($stage === 'apply') { $journal['apply']['state'] = $unknown ? 'unknown' : 'failed'; }
                $code = preg_match('/^[A-Z_]{3,64}$/D', $exception->getMessage()) ? $exception->getMessage() : 'ADAPTER_OUTCOME_UNVERIFIED';
                $journal['error'] = ['stage' => $stage, 'operationId' => $currentId, 'code' => $code,
                    'recovery' => 'Retry the same blueprint after checking target state, secrets, versions and conflicts. Partial objects need explicit operator reconciliation; never blindly recreate or overwrite.'];
                $this->checkpoint($scope, $journal);
                return $journal;
            }
        });
    }

    public function executeDesiredState(array $blueprint, array $plan, string $planId, bool $authorised = false): array
    {
        if (!$authorised) { throw new RuntimeException('Explicit local configuration mutation authorisation required.'); }
        if (!$this->target instanceof ReconciliationTarget) { throw new RuntimeException('TARGET_RECONCILIATION_UNSUPPORTED'); }
        Blueprint::verifyHash($blueprint);
        DesiredStatePlanner::verify($plan);
        if (($plan['planId'] ?? '') !== $planId || ($plan['blueprintHash'] ?? '') !== $blueprint['contentHash']
            || ($plan['sourcePbxId'] ?? '') !== $blueprint['sourcePbxId']
            || !in_array($plan['action'] ?? '', ['revert','regenerate'], true)) {
            throw new RuntimeException('DESIRED_STATE_PLAN_BINDING_INVALID');
        }
        $scope = $this->target->identity() . ':' . $plan['sourcePbxId'] . ':' . $planId;
        return $this->store->exclusive($this->target->identity(), function () use ($blueprint, $plan, $planId, $scope): array {
            $journal = $this->store->update(static function (array &$state) use ($scope, $plan): array {
                if (!isset($state['desiredStateBuilds'][$scope])) {
                    $steps = [];
                    foreach ($plan['mutations'] as $mutation) { $steps[$mutation['id']] = ['state' => 'pending']; }
                    $state['desiredStateBuilds'][$scope] = ['planId' => $plan['planId'], 'planHash' => $plan['planHash'],
                        'revisionId' => $plan['revisionId'], 'action' => $plan['action'], 'targetId' => $plan['targetId'],
                        'state' => 'pending', 'mutations' => $steps, 'apply' => ['state' => 'pending'],
                        'verified' => false, 'createdAt' => gmdate(DATE_ATOM)];
                }
                $journal = $state['desiredStateBuilds'][$scope];
                if (($journal['planHash'] ?? '') !== $plan['planHash']) { throw new RuntimeException('DESIRED_STATE_PLAN_ID_CONFLICT'); }
                return $journal;
            });
            $snapshot = $this->target->reconciliationSnapshot($this->secrets, $blueprint['sourcePbxId']);
            $environment = $this->target->environment();
            foreach ($blueprint['modules'] as $module => $version) {
                if (($environment['modules'][$module] ?? '') !== $version) { throw new RuntimeException('MODULE_VERSION_MISMATCH'); }
            }
            $desiredOperations = BuildSpec::plan($blueprint);
            foreach ($desiredOperations as $operation) {
                if (array_diff($operation['requiredCapabilities'], $environment['capabilities'] ?? [])) {
                    throw new RuntimeException('CAPABILITY_UNAVAILABLE');
                }
                foreach ($operation['secretRefs'] as $reference) { $this->secrets->resolve($reference, $blueprint['sourcePbxId']); }
            }
            $this->target->preflight($desiredOperations);
            $capabilities = $this->target->reconciliationCapabilities();
            foreach ($plan['capabilities'] as $name => $value) {
                if (($capabilities[$name] ?? false) !== $value) { throw new RuntimeException('TARGET_CAPABILITY_CHANGED'); }
            }
            if (empty($capabilities['apply'])) { throw new RuntimeException('TARGET_APPLY_UNSUPPORTED'); }
            foreach ($plan['mutations'] as $mutation) {
                $required = ['create' => 'create', 'update' => 'update', 'remove' => 'remove'][$mutation['mutation'] ?? ''] ?? null;
                if ($required === null || empty($capabilities[$required])) { throw new RuntimeException('TARGET_' . strtoupper((string) $required) . '_UNSUPPORTED'); }
            }
            $snapshotCheck = $this->reconcileDesiredSnapshot($plan, $snapshot, $journal);
            if ($snapshotCheck === false) { throw new RuntimeException('DESIRED_STATE_PLAN_STALE'); }
            if (is_array($snapshotCheck)) {
                $journal['mutations'] = $snapshotCheck + $journal['mutations'];
                $this->checkpointDesired($scope, $journal);
            }
            if (!empty($plan['conflicts']) || !empty($plan['unsupportedDifferences'])) {
                return $this->desiredStateFailure($scope, $journal, 'DESIRED_STATE_PLAN_HAS_BLOCKERS', 'preflight');
            }
            if ($plan['mutations'] === []) {
                $journal['state'] = 'configuration_verified';
                $journal['verified'] = true;
                $journal['targetSnapshotHash'] = $plan['targetSnapshotHash'];
                $this->checkpointDesired($scope, $journal);
                return $journal;
            }
            $stage = 'preflight';
            $currentId = null;
            try {
                foreach ($plan['mutations'] as $mutation) {
                    $currentId = $mutation['id'];
                    $mutationType = $mutation['mutation'];
                    $requiredCapability = ['create' => 'create', 'update' => 'update', 'remove' => 'remove'][$mutationType] ?? null;
                    if ($requiredCapability === null || empty($capabilities[$requiredCapability])) {
                        throw new RuntimeException('TARGET_' . strtoupper($mutationType) . '_UNSUPPORTED');
                    }
                    $checkpoint = $journal['mutations'][$currentId] ?? ['state' => 'pending'];
                    $fresh = $this->target->reconciliationSnapshot($this->secrets, $blueprint['sourcePbxId']);
                    $actual = $fresh[$currentId] ?? ['state' => 'absent'];
                    if (($checkpoint['state'] ?? '') === 'succeeded') {
                        if (!$this->desiredMutationSatisfied($actual, $mutation, $mutationType === 'remove')) {
                            throw new RuntimeException('DESIRED_STATE_SUCCESS_CHECKPOINT_INVALIDATED');
                        }
                        continue;
                    }
                    if ($checkpoint['state'] === 'running' || $checkpoint['state'] === 'unknown') {
                        if ($this->desiredMutationSatisfied($actual, $mutation, $requiredCapability === 'remove')) {
                            $journal['mutations'][$currentId] = ['state' => 'succeeded', 'reconciled' => true, 'at' => gmdate(DATE_ATOM)];
                            $this->checkpointDesired($scope, $journal);
                            continue;
                        }
                    }
                    $expectedHash = $mutation['preconditions']['snapshotEntryHash'] ?? '';
                    if (DesiredStatePlanner::fingerprint(DesiredStatePlanner::normaliseEntry($actual)) !== $expectedHash) {
                        if ($mutationType !== 'create' || ($actual['state'] ?? '') !== 'absent') {
                            throw new RuntimeException('DESIRED_STATE_TARGET_CHANGED');
                        }
                    }
                    if ($mutationType !== 'create') {
                        if (($actual['state'] ?? '') !== 'present' || ($actual['owner'] ?? '') !== ($mutation['preconditions']['expectedOwner'] ?? '')
                            || !empty($actual['shared']) || empty($actual['secretReferenceAvailable'] ?? true)) {
                            throw new RuntimeException('TARGET_OWNERSHIP_OR_SECRET_UNVERIFIED');
                        }
                    }
                    $journal['state'] = 'running';
                    $journal['mutations'][$currentId] = ['state' => 'running', 'mutation' => $mutationType,
                        'beforeHash' => $expectedHash, 'at' => gmdate(DATE_ATOM)];
                    $this->checkpointDesired($scope, $journal);
                    $stage = 'mutation';
                    $operation = $mutation['operation'];
                    if ($mutationType === 'create') { $this->target->create($operation, $this->secrets, $blueprint['sourcePbxId']); }
                    elseif ($mutationType === 'update') { $this->target->update($operation, $this->secrets, $blueprint['sourcePbxId']); }
                    else { $this->target->remove($operation, $this->secrets, $blueprint['sourcePbxId']); }
                    if ($this->observer) { ($this->observer)('after_desired_mutation', $currentId, $mutationType); }
                    $stage = 'verification';
                    $fresh = $this->target->reconciliationSnapshot($this->secrets, $blueprint['sourcePbxId']);
                    $actual = $fresh[$currentId] ?? ['state' => 'absent'];
                    if (!$this->desiredMutationSatisfied($actual, $mutation, $mutationType === 'remove')) {
                        throw new RuntimeException('DESIRED_STATE_READBACK_MISMATCH');
                    }
                    $journal['mutations'][$currentId] = ['state' => 'succeeded', 'mutation' => $mutationType,
                        'afterHash' => DesiredStatePlanner::fingerprint($actual), 'at' => gmdate(DATE_ATOM)];
                    $this->checkpointDesired($scope, $journal);
                }
                $stage = 'apply';
                $journal['apply'] = ['state' => 'running', 'at' => gmdate(DATE_ATOM)];
                $this->checkpointDesired($scope, $journal);
                $apply = $this->target->apply();
                if (($apply['generation'] ?? '') !== 'succeeded' || ($apply['reload'] ?? '') !== 'succeeded') {
                    throw new RuntimeException('APPLY_NOT_CONFIRMED');
                }
                $journal['apply'] = ['state' => 'succeeded', 'receipt' => $apply, 'at' => gmdate(DATE_ATOM)];
                $this->checkpointDesired($scope, $journal);
                $stage = 'post_apply_verification';
                $post = $this->target->reconciliationSnapshot($this->secrets, $blueprint['sourcePbxId']);
                foreach ($plan['mutations'] as $mutation) {
                    $actual = $post[$mutation['id']] ?? ['state' => 'absent'];
                    if (!$this->desiredMutationSatisfied($actual, $mutation, $mutation['mutation'] === 'remove')) {
                        throw new RuntimeException('POST_APPLY_CONFIGURATION_MISMATCH');
                    }
                }
                $journal['state'] = 'configuration_verified';
                $journal['verified'] = true;
                $journal['verification'] = ['scope' => $plan['scope'], 'targetSnapshotHash' => DesiredStatePlanner::fingerprint(DesiredStatePlanner::normaliseSnapshot($post)),
                    'verifiedAt' => gmdate(DATE_ATOM), 'claim' => 'supported configuration read-back only; no runtime/call/audio claim'];
                unset($journal['error']);
                $this->checkpointDesired($scope, $journal);
                return $journal;
            } catch (\Throwable $exception) {
                $unknown = $stage === 'mutation' || ($stage === 'apply' && ($journal['apply']['state'] ?? '') === 'running');
                if ($currentId !== null && $stage === 'mutation') { $journal['mutations'][$currentId]['state'] = 'unknown'; }
                $journal['state'] = $unknown ? 'unknown' : 'failed';
                $journal['error'] = ['stage' => $stage, 'objectId' => $currentId,
                    'code' => preg_match('/^[A-Z0-9_]{3,64}$/D', $exception->getMessage()) ? $exception->getMessage() : 'LOCAL_MUTATION_UNVERIFIED',
                    'recovery' => 'Inspect the target, reconcile the same plan and operation history, then request a fresh preview if the snapshot changed. No reverse mutation was attempted.'];
                $this->checkpointDesired($scope, $journal);
                return $journal;
            }
        });
    }

    private function desiredMutationSatisfied(array $actual, array $mutation, bool $shouldBeAbsent): bool
    {
        if ($shouldBeAbsent) { return ($actual['state'] ?? '') === 'absent'; }
        $operation = $mutation['operation'];
        return ($actual['state'] ?? '') === 'present' && ($actual['owner'] ?? '') === ($mutation['preconditions']['expectedOwner'] ?? '')
            && empty($actual['shared']) && ($actual['secretMatches'] ?? false) === true
            && DesiredStatePlanner::fingerprint(['value' => $actual['parameters'] ?? null])
                === DesiredStatePlanner::fingerprint(['value' => $operation['parameters']]);
    }

    private function reconcileDesiredSnapshot(array $plan, array $snapshot, array $journal): array|false
    {
        $before = $plan['targetSnapshot'];
        $current = DesiredStatePlanner::normaliseSnapshot($snapshot);
        $steps = [];
        foreach ($plan['mutations'] as $mutation) { $steps[$mutation['id']] = $mutation; }
        $reconciled = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($current))) as $id) {
            $beforeEntry = $before[$id] ?? ['state' => 'absent'];
            $currentEntry = $current[$id] ?? ['state' => 'absent'];
            $checkpoint = $journal['mutations'][$id] ?? ['state' => 'pending'];
            if (!isset($steps[$id]) || !in_array($checkpoint['state'] ?? '', ['succeeded','running','unknown'], true)) {
                if (DesiredStatePlanner::fingerprint($beforeEntry) !== DesiredStatePlanner::fingerprint($currentEntry)) { return false; }
                continue;
            }
            $mutation = $steps[$id];
            $isRemove = ($mutation['mutation'] ?? '') === 'remove';
            $desiredReached = $this->desiredMutationSatisfied($currentEntry, $mutation, $isRemove);
            if (($checkpoint['state'] ?? '') === 'succeeded') {
                if (!$desiredReached) { return false; }
                continue;
            }
            if ($desiredReached) {
                $reconciled[$id] = ['state' => 'succeeded', 'mutation' => $mutation['mutation'],
                    'reconciled' => true, 'afterHash' => DesiredStatePlanner::fingerprint($currentEntry), 'at' => gmdate(DATE_ATOM)];
                continue;
            }
            if (DesiredStatePlanner::fingerprint($beforeEntry) !== DesiredStatePlanner::fingerprint($currentEntry)) { return false; }
        }
        return $reconciled;
    }

    private function checkpointDesired(string $scope, array &$journal): void
    {
        $journal['updatedAt'] = gmdate(DATE_ATOM);
        $this->store->update(static function (array &$state) use ($scope, $journal): void {
            $state['desiredStateBuilds'][$scope] = $journal;
        });
    }

    private function desiredStateFailure(string $scope, array $journal, string $code, string $stage): array
    {
        $journal['state'] = 'failed';
        $journal['error'] = ['stage' => $stage, 'code' => $code];
        $this->checkpointDesired($scope, $journal);
        return $journal;
    }

    private function compare(array $operation, array $actual): void
    {
        if (($actual['state'] ?? '') === 'absent') { return; }
        if (($actual['state'] ?? '') !== 'present') { throw new RuntimeException('PARTIAL_TARGET_REQUIRES_RECONCILIATION'); }
        if (BuildSpec::seal(['value' => $actual['parameters'] ?? null]) !== BuildSpec::seal(['value' => $operation['parameters']])
            || ($actual['secretMatches'] ?? false) !== true) { throw new RuntimeException('TARGET_CONFIGURATION_CONFLICT'); }
    }

    private function assertOwnership(string $scope, array $operation): void
    {
        $owner = $this->store->update(fn (array &$state): ?string => $state['objectOwners'][$this->target->identity()][$operation['id']] ?? null);
        if ($owner !== null && $owner !== $scope) { throw new RuntimeException('FOREIGN_RECONSTRUCTION_OWNER'); }
    }

    private function checkpoint(string $scope, array &$journal, ?array $claim = null): void
    {
        $journal['updatedAt'] = gmdate(DATE_ATOM);
        $this->store->update(function (array &$state) use ($scope, $journal, $claim): void {
            $state['builds'][$scope] = $journal;
            if ($claim) { $state['objectOwners'][$this->target->identity()][$claim['id']] = $scope; }
        });
    }
}