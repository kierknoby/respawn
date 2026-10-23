<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

use RuntimeException;

final class DesiredStatePlanner
{
    public static function plan(array $blueprint, array $current, array $managedScope, string $targetId,
        string $revisionId, string $action, array $capabilities, string $owner): array
    {
        if (!in_array($action, ['revert','regenerate'], true)) { throw new RuntimeException('LOCAL_ACTION_INVALID'); }
        Blueprint::verifyHash($blueprint);
        $desired = BuildSpec::plan($blueprint);
        $scopeIds = $managedScope['objectIds'] ?? [];
        if (!is_array($scopeIds) || count(array_filter($scopeIds, 'is_string')) !== count($scopeIds)
            || count(array_unique($scopeIds)) !== count($scopeIds)) { throw new RuntimeException('MANAGED_SCOPE_INVALID'); }
        foreach ($scopeIds as $id) { self::objectId($id); }
        foreach ($current as $id => $entry) {
            self::objectId((string) $id);
            if (!is_array($entry) || !in_array($entry['state'] ?? '', ['present','absent','partial'], true)) {
                throw new RuntimeException('TARGET_SNAPSHOT_INVALID');
            }
        }
        $orderedCurrent = [];
        foreach ($desired as $operation) {
            $id = $operation['id'];
            $actual = $current[$id] ?? ['state' => 'absent'];
            $managed = in_array($id, $scopeIds, true);
            $record = ['id' => $id, 'operation' => $operation, 'dependencies' => $operation['dependsOn'],
                'before' => self::normaliseEntry($actual), 'reason' => null, 'mutation' => null,
                'preconditions' => ['snapshotEntryHash' => self::fingerprint(self::normaliseEntry($actual)),
                    'expectedOwner' => $owner, 'dependencies' => $operation['dependsOn']]];
            if ($actual['state'] === 'absent') {
                $record['mutation'] = 'create';
                $record['reason'] = 'selected blueprint object is absent on the target';
                if (empty($capabilities['create'])) { $record['mutation'] = null; $record['reasonCode'] = 'TARGET_CREATE_UNSUPPORTED'; }
            } elseif ($actual['state'] === 'partial') {
                $record['reasonCode'] = 'PARTIAL_TARGET_REQUIRES_RECONCILIATION';
            } else {
                $same = self::sameParameters($actual['parameters'] ?? null, $operation['parameters'])
                    && ($actual['secretMatches'] ?? false) === true;
                if ($same) {
                    $record['reason'] = 'supported configuration already matches this revision';
                } elseif (!empty($actual['shared']) || ($actual['owner'] ?? '') !== $owner) {
                    $record['reasonCode'] = 'TARGET_CONFIGURATION_CONFLICT';
                } elseif (($actual['secretMatches'] ?? false) !== true && !empty($operation['secretRefs'])) {
                    $record['reasonCode'] = 'SECRET_REFERENCE_UNRESOLVED';
                } elseif (!$managed) {
                    $record['reasonCode'] = 'TARGET_OBJECT_OUTSIDE_MANAGED_SCOPE';
                } elseif (!empty($capabilities['update'])) {
                    $record['mutation'] = 'update';
                    $record['reason'] = 'owned object differs from the selected revision';
                } else {
                    $record['reasonCode'] = 'TARGET_UPDATE_UNSUPPORTED';
                }
            }
            $orderedCurrent[$id] = $record;
        }

        $desiredIds = array_keys($orderedCurrent);
        $removals = [];
        $unsupportedDifferences = [];
        foreach ($scopeIds as $id) {
            if (in_array($id, $desiredIds, true)) { continue; }
            $actual = $current[$id] ?? ['state' => 'absent'];
            if ($actual['state'] === 'absent') { continue; }
            if (empty($managedScope['complete'])) {
                $unsupportedDifferences[] = ['id' => $id, 'reasonCode' => 'PARTIAL_BLUEPRINT_DOES_NOT_AUTHORISE_REMOVAL'];
                continue;
            }
            if ($actual['state'] !== 'present' || !empty($actual['shared']) || ($actual['owner'] ?? '') !== $owner) {
                $removals[$id] = ['id' => $id, 'reasonCode' => 'REMOVAL_OWNERSHIP_UNVERIFIED', 'before' => self::normaliseEntry($actual)];
                continue;
            }
            if (empty($capabilities['remove'])) {
                $unsupportedDifferences[] = ['id' => $id, 'reasonCode' => 'TARGET_REMOVAL_UNSUPPORTED'];
                continue;
            }
            $operation = self::removalOperation($id, $actual);
            $removals[$id] = ['id' => $id, 'operation' => $operation,
                'before' => self::normaliseEntry($actual), 'reason' => 'previously managed object is absent from the selected complete revision',
                'preconditions' => ['snapshotEntryHash' => self::fingerprint(self::normaliseEntry($actual)),
                    'expectedOwner' => $owner, 'dependencies' => $actual['dependsOn'] ?? []]];
        }

        $mutationConflicts = array_values(array_filter($orderedCurrent, static fn (array $entry): bool => isset($entry['reasonCode'])));
        foreach ($removals as $id => $entry) {
            if (isset($entry['reasonCode'])) { $mutationConflicts[] = $entry; unset($removals[$id]); }
        }
        $orderedRemovals = self::orderRemovals($removals, $current, $orderedCurrent, $mutationConflicts);
        $mutations = [];
        foreach ($orderedCurrent as $entry) {
            if ($entry['mutation'] !== null) { $mutations[] = $entry; }
        }
        foreach ($orderedRemovals as $entry) { $mutations[] = $entry + ['mutation' => 'remove']; }
        $snapshot = self::normaliseSnapshot($current);
        $plan = ['schema' => 'respawn.desired-state-plan.v1', 'action' => $action,
            'sourcePbxId' => $blueprint['sourcePbxId'], 'targetId' => $targetId,
            'revisionId' => $revisionId, 'blueprintHash' => $blueprint['contentHash'],
            'scope' => ['complete' => ($managedScope['complete'] ?? false) === true, 'objectIds' => $scopeIds],
            'targetSnapshotHash' => self::fingerprint($snapshot), 'targetSnapshot' => $snapshot,
            'coverage' => $blueprint['coverage'], 'mutations' => $mutations,
            'alreadyMatching' => array_values(array_filter($orderedCurrent, static fn (array $entry): bool => $entry['mutation'] === null && !isset($entry['reasonCode']))),
            'conflicts' => $mutationConflicts, 'unsupportedDifferences' => $unsupportedDifferences,
            'capabilities' => $capabilities, 'createdAt' => gmdate(DATE_ATOM)];
        $plan['planId'] = 'plan-' . substr(self::fingerprint($plan), 0, 32);
        $plan['planHash'] = self::fingerprint($plan);
        return $plan;
    }

    public static function verify(array $plan): void
    {
        $expected = $plan['planHash'] ?? '';
        unset($plan['planHash']);
        if (!is_string($expected) || !hash_equals(self::fingerprint($plan), $expected)) {
            throw new RuntimeException('DESIRED_STATE_PLAN_INTEGRITY_FAILED');
        }
    }

    public static function reseal(array $plan): array
    {
        unset($plan['planHash']);
        $plan['planHash'] = self::fingerprint($plan);
        return $plan;
    }

    public static function fingerprint(array $value): string
    {
        return hash('sha256', BuildSpec::canonicalBytes($value));
    }

    private static function orderRemovals(array $removals, array $current, array $desired, array &$conflicts): array
    {
        $remaining = array_fill_keys(array_keys($removals), true);
        $afterDependencies = [];
        foreach ($current as $id => $entry) {
            if (isset($desired[$id]) && ($desired[$id]['mutation'] ?? null) === 'update') {
                $afterDependencies[$id] = $desired[$id]['dependencies'];
            } else { $afterDependencies[$id] = $entry['dependsOn'] ?? []; }
        }
        foreach ($removals as $id => $entry) { $afterDependencies[$id] = $entry['before']['dependsOn'] ?? []; }
        foreach ($remaining as $id => $_) {
            foreach ($afterDependencies as $dependentId => $dependencies) {
                if ($dependentId !== $id && !isset($remaining[$dependentId]) && in_array($id, $dependencies, true)) {
                    $conflicts[] = ['id' => $id, 'reasonCode' => 'DEPENDENT_OBJECT_REMAINS', 'dependentId' => $dependentId];
                    unset($remaining[$id]);
                    unset($removals[$id]);
                    break;
                }
            }
        }
        $result = [];
        while ($remaining !== []) {
            $progress = false;
            foreach (array_keys($remaining) as $id) {
                $hasRemainingDependent = false;
                foreach ($remaining as $dependentId => $_) {
                    if ($dependentId !== $id && in_array($id, $afterDependencies[$dependentId] ?? [], true)) {
                        $hasRemainingDependent = true;
                        break;
                    }
                }
                if ($hasRemainingDependent) { continue; }
                $result[] = $removals[$id];
                unset($remaining[$id]);
                $progress = true;
            }
            if (!$progress) {
                foreach (array_keys($remaining) as $id) { $conflicts[] = ['id' => $id, 'reasonCode' => 'REMOVAL_DEPENDENCY_CYCLE']; }
                break;
            }
        }
        return $result;
    }

    private static function removalOperation(string $id, array $actual): array
    {
        $typeAndNumber = explode(':', $id, 2);
        $type = $typeAndNumber[0] ?? '';
        $adapter = ['user' => 'core.user.v1', 'pjsip' => 'core.pjsip.v1', 'ringgroup' => 'ringgroups.v1'][$type] ?? '';
        if ($adapter === '') { throw new RuntimeException('REMOVAL_ADAPTER_UNKNOWN'); }
        return ['id' => $id, 'adapter' => $adapter, 'object' => ['type' => $type, 'identity' => $typeAndNumber[1]],
            'parameters' => $actual['parameters'] ?? [], 'secretRefs' => $actual['secretRefs'] ?? [],
            'dependsOn' => $actual['dependsOn'] ?? []];
    }

    private static function sameParameters(mixed $actual, array $desired): bool
    {
        return is_array($actual) && self::fingerprint(['value' => $actual]) === self::fingerprint(['value' => $desired]);
    }

    public static function normaliseEntry(array $entry): array
    {
        $safe = array_intersect_key($entry, array_flip(['state','parameters','secretMatches','owner','shared','dependsOn',
            'revision','adapter','object','secretRefs']));
        return $safe + ['state' => $entry['state'] ?? 'absent'];
    }

    public static function normaliseSnapshot(array $current): array
    {
        ksort($current, SORT_STRING);
        foreach ($current as &$entry) { $entry = self::normaliseEntry($entry); }
        unset($entry);
        return $current;
    }

    private static function objectId(string $id): void
    {
        if (!preg_match('/^(user|pjsip|ringgroup):[0-9]{2,8}$/D', $id)) { throw new RuntimeException('MANAGED_SCOPE_OBJECT_INVALID'); }
    }
}