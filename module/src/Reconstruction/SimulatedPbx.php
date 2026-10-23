<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class SimulatedPbx implements ReconciliationTarget
{
    public ?string $failBefore = null;
    public ?string $loseResponse = null;
    public bool $failApply = false;
    public bool $badReadback = false;
    public ?string $failUpdate = null;
    public ?string $failRemove = null;
    public ?string $loseUpdateResponse = null;
    public ?string $loseRemoveResponse = null;
    public array $modules;

    public function __construct(private JsonStateStore $objects, private string $targetId, private string $owner = 'test-build')
    {
        $this->modules = self::moduleVersions();
    }

    public static function moduleVersions(): array
    {
        return ['framework' => '17.0.1', 'core' => '17.0.1', 'ringgroups' => '17.0.1', 'sipsettings' => '17.0.1'];
    }

    public static function blueprint(string $source): array
    {
        $bravo = $source === 'pbx-bravo';
        $first = $bravo ? '2001' : '1001';
        $second = $bravo ? '2002' : '1002';
        $primary = $bravo ? '700' : '600';
        $fallback = $bravo ? '701' : '601';
        $prefix = $bravo ? 'Bravo' : 'Alpha';
        $settings = ['context' => 'from-internal', 'transport' => '', 'dtmfmode' => 'rfc4733',
            'allow' => 'ulaw,alaw', 'disallow' => 'all', 'direct_media' => 'no', 'rtp_symmetric' => 'yes',
            'force_rport' => 'yes', 'rewrite_contact' => 'yes', 'max_contacts' => 1,
            'remove_existing' => 'yes', 'media_encryption' => 'no'];
        $users = []; $devices = [];
        foreach ([$first, $second] as $number) {
            $users[$number] = ['name' => $prefix . ' ' . $number, 'voicemail' => 'novm'];
            $devices[$number] = ['user' => $number, 'description' => $prefix . ' ' . $number,
                'authUsername' => $prefix . '-' . $number, 'settings' => $settings, 'secretRef' => 'secret:' . $source . ':' . $number . ':v1'];
        }
        return BuildSpec::create($source, self::moduleVersions(), $users, $devices, [
            ['number' => $primary, 'description' => $prefix . ' primary', 'strategy' => 'ringall', 'timeout' => 20,
                'members' => [$first], 'destination' => $fallback],
            ['number' => $fallback, 'description' => $prefix . ' fallback', 'strategy' => 'ringall', 'timeout' => 15,
                'members' => [$second], 'destination' => 'hangup'],
        ]);
    }

    public function identity(): string { return $this->targetId; }
    public function environment(): array
    {
        return ['mode' => 'SIMULATED', 'modules' => $this->modules, 'capabilities' => array_keys(BuildSpec::ADAPTERS)];
    }
    public function preflight(array $operations): void {}

    public function inspect(array $operation, SecretResolver $secrets, string $sourcePbxId): array
    {
        return $this->objects->update(function (array &$state) use ($operation, $secrets, $sourcePbxId): array {
            $record = $state['objects'][$operation['id']] ?? null;
            if ($record === null) { return ['state' => 'absent']; }
            if ($record['state'] !== 'complete') { return ['state' => 'partial']; }
            $parameters = $record['parameters'];
            if ($this->badReadback) { $parameters['unexpected'] = true; }
            $matches = true;
            foreach ($operation['secretRefs'] as $reference) {
                $matches = $matches && hash_equals($record['authDigest'], hash('sha256', $secrets->resolve($reference, $sourcePbxId)));
            }
            return ['state' => 'present', 'parameters' => $parameters, 'secretMatches' => $matches];
        });
    }

    public function create(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        if ($operation['id'] === $this->failBefore) { throw new RuntimeException('SIMULATED_MUTATION_FAILED'); }
        $this->objects->update(function (array &$state) use ($operation, $secrets, $sourcePbxId): void {
            if (isset($state['objects'][$operation['id']])) { throw new RuntimeException('DUPLICATE_CREATE_PROHIBITED'); }
            foreach ($operation['dependsOn'] as $dependency) {
                if (($state['objects'][$dependency]['state'] ?? '') !== 'complete') { throw new RuntimeException('TARGET_DEPENDENCY_MISSING'); }
            }
            $record = ['state' => 'complete', 'parameters' => $operation['parameters'], 'owner' => $this->owner,
                'shared' => false, 'dependsOn' => $operation['dependsOn'], 'secretRefs' => $operation['secretRefs'], 'revision' => 1];
            foreach ($operation['secretRefs'] as $reference) { $record['authDigest'] = hash('sha256', $secrets->resolve($reference, $sourcePbxId)); }
            $state['objects'][$operation['id']] = $record;
            $state['creationOrder'][] = $operation['id'];
        });
        if ($operation['id'] === $this->loseResponse) { throw new RuntimeException('SIMULATED_RESPONSE_LOST'); }
    }

    public function apply(): array
    {
        if ($this->failApply) { throw new RuntimeException('SIMULATED_APPLY_FAILED'); }
        $this->objects->update(static function (array &$state): void { $state['applyCount'] = ($state['applyCount'] ?? 0) + 1; });
        return ['mode' => 'SIMULATED', 'generation' => 'succeeded', 'reload' => 'succeeded', 'runtime' => 'not_verified'];
    }

    public function reconciliationCapabilities(): array
    {
        return ['export' => true, 'inspect' => true, 'create' => true, 'update' => true, 'remove' => true,
            'apply' => true, 'verify' => true, 'regenerate' => true, 'revert' => true, 'mode' => 'SIMULATED'];
    }

    public function managedOwner(): string { return $this->owner; }

    public function reconciliationSnapshot(SecretResolver $secrets, string $sourcePbxId, array $operationCatalog = []): array
    {
        return $this->objects->update(function (array &$state) use ($secrets, $sourcePbxId): array {
            $snapshot = [];
            foreach ($state['objects'] ?? [] as $id => $record) {
                $secretMatches = true;
                $secretAvailable = true;
                foreach ($record['secretRefs'] ?? [] as $reference) {
                    try {
                        $secretMatches = $secretMatches && isset($record['authDigest'])
                            && hash_equals($record['authDigest'], hash('sha256', $secrets->resolve($reference, $sourcePbxId)));
                    } catch (RuntimeException) { $secretAvailable = false; $secretMatches = false; }
                }
                $snapshot[$id] = ['state' => ($record['state'] ?? '') === 'complete' ? 'present' : 'partial',
                    'parameters' => $record['parameters'] ?? [],
                    'owner' => $record['owner'] ?? 'unknown', 'shared' => ($record['shared'] ?? false) === true,
                    'dependsOn' => $record['dependsOn'] ?? [], 'revision' => $record['revision'] ?? 0,
                    'secretRefs' => $record['secretRefs'] ?? [], 'secretMatches' => $secretMatches,
                    'secretReferenceAvailable' => $secretAvailable];
            }
            ksort($snapshot, SORT_STRING);
            return $snapshot;
        });
    }

    public function update(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        $id = $operation['id'];
        if ($this->failUpdate === $id) { throw new RuntimeException('SIMULATED_UPDATE_FAILED'); }
        $this->objects->update(function (array &$state) use ($operation, $secrets, $sourcePbxId, $id): void {
            $record = &$state['objects'][$id];
            if (!isset($record['state']) || $record['state'] !== 'complete' || $record['owner'] !== $this->owner || $record['shared']) {
                throw new RuntimeException('TARGET_OWNERSHIP_CONFLICT');
            }
            foreach ($operation['dependsOn'] as $dependency) {
                if (($state['objects'][$dependency]['state'] ?? '') !== 'complete') { throw new RuntimeException('TARGET_DEPENDENCY_MISSING'); }
            }
            $record['parameters'] = $operation['parameters'];
            $record['dependsOn'] = $operation['dependsOn'];
            $record['secretRefs'] = $operation['secretRefs'];
            unset($record['authDigest']);
            foreach ($operation['secretRefs'] as $reference) { $record['authDigest'] = hash('sha256', $secrets->resolve($reference, $sourcePbxId)); }
            $record['revision'] = ($record['revision'] ?? 0) + 1;
        });
        if ($this->loseUpdateResponse === $id) { throw new RuntimeException('SIMULATED_UPDATE_RESPONSE_LOST'); }
    }

    public function remove(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        $id = $operation['id'];
        if ($this->failRemove === $id) { throw new RuntimeException('SIMULATED_REMOVE_FAILED'); }
        $this->objects->update(function (array &$state) use ($id): void {
            $record = $state['objects'][$id] ?? null;
            if (!is_array($record) || ($record['owner'] ?? '') !== $this->owner || ($record['shared'] ?? true)) {
                throw new RuntimeException('TARGET_OWNERSHIP_CONFLICT');
            }
            foreach ($state['objects'] as $otherId => $other) {
                if ($otherId !== $id && in_array($id, $other['dependsOn'] ?? [], true)) {
                    throw new RuntimeException('TARGET_HAS_DEPENDENTS');
                }
            }
            unset($state['objects'][$id]);
            $state['removalOrder'][] = $id;
        });
        if ($this->loseRemoveResponse === $id) { throw new RuntimeException('SIMULATED_REMOVE_RESPONSE_LOST'); }
    }

    public function export(string $sourcePbxId, array $selection, SecretResolver $secrets): array
    {
        $snapshot = $this->snapshot();
        $users = []; $devices = []; $groups = [];
        foreach ($selection as $id) {
            $record = $snapshot['objects'][$id] ?? null;
            if (!$record || $record['state'] !== 'complete') { throw new RuntimeException('SOURCE_OBJECT_INCOMPLETE'); }
            [$type, $number] = explode(':', $id, 2);
            $parameters = $record['parameters'];
            if ($type === 'user') { $users[$number] = $parameters; }
            elseif ($type === 'pjsip') {
                $reference = $secrets->reference($sourcePbxId, $number);
                if (!hash_equals($record['authDigest'], hash('sha256', $secrets->resolve($reference, $sourcePbxId)))) {
                    throw new RuntimeException('SOURCE_SECRET_REFERENCE_MISMATCH');
                }
                $devices[$number] = $parameters + ['secretRef' => $reference];
            } else { $groups[] = ['number' => $number] + $parameters; }
        }
        return BuildSpec::create($sourcePbxId, $this->modules, $users, $devices, $groups);
    }

    public function cleanup(): array
    {
        return $this->objects->update(function (array &$state): array {
            foreach (array_reverse(array_keys($state['objects'] ?? [])) as $id) {
                $record = $state['objects'][$id];
                $hasDependents = false;
                foreach ($state['objects'] as $other) {
                    if (in_array($id, $other['dependsOn'] ?? [], true)) { $hasDependents = true; }
                }
                if ($record['owner'] === $this->owner && !$record['shared'] && !$hasDependents) { unset($state['objects'][$id]); }
            }
            return array_keys($state['objects'] ?? []);
        });
    }
    public function snapshot(): array { return $this->objects->update(static fn (array &$state): array => $state); }
}