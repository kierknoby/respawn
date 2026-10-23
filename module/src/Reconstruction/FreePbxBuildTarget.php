<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use RuntimeException;

final class FreePbxBuildTarget implements ReconciliationTarget
{
    private bool $applyAvailable;

    public function __construct(private object $core, private FreePbxRinggroups $groups, private string $targetId,
        private \Closure $snapshot, private \Closure $environmentReader, private \Closure $applyRunner,
        private \Closure $runtimePreflight, bool $applyAvailable = true)
    {
        $this->applyAvailable = $applyAvailable;
    }

    public function identity(): string { return $this->targetId; }
    public function environment(): array { return ($this->environmentReader)(); }

    public function reconciliationCapabilities(): array
    {
        return ['export' => true, 'inspect' => true, 'create' => true, 'update' => false, 'remove' => false,
            'apply' => $this->applyAvailable, 'verify' => true, 'regenerate' => true, 'revert' => false, 'mode' => 'FREEPBX_NATIVE_UNVERIFIED'];
    }

    public function managedOwner(): string { return 'native_ownership_unverified'; }

    public function reconciliationSnapshot(SecretResolver $secrets, string $sourcePbxId, array $operationCatalog = []): array
    {
        $snapshot = [];
        foreach ($operationCatalog as $id => $operation) {
            if (!is_array($operation) || ($operation['id'] ?? '') !== $id) { throw new RuntimeException('REVISION_OPERATION_CATALOG_INVALID'); }
            $actual = $this->inspect($operation, $secrets, $sourcePbxId);
            $snapshot[$id] = $actual + ['owner' => 'native_ownership_unverified', 'shared' => true,
                'dependsOn' => $operation['dependsOn'] ?? [], 'revision' => null,
                'secretRefs' => $operation['secretRefs'] ?? [], 'secretReferenceAvailable' => true];
        }
        ksort($snapshot, SORT_STRING);
        return $snapshot;
    }

    public function update(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        throw new RuntimeException('NATIVE_TARGET_UPDATE_UNVERIFIED');
    }

    public function remove(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        throw new RuntimeException('NATIVE_TARGET_REMOVAL_UNVERIFIED');
    }

    public function preflight(array $operations): void
    {
        ($this->runtimePreflight)($operations);
        foreach ($operations as $operation) {
            $number = $operation['object']['identity'];
            $isGroup = $operation['object']['type'] === 'ringgroup';
            if ($isGroup) {
                $user = ($this->snapshot)('user', $number);
                $device = ($this->snapshot)('pjsip', $number);
                if ($user['row'] || $device['row'] || $device['settings']) { throw new RuntimeException('DIAL_IDENTITY_COLLISION'); }
            } elseif ($this->groups->read($number) !== null) { throw new RuntimeException('DIAL_IDENTITY_COLLISION'); }
        }
    }

    public function inspect(array $operation, SecretResolver $secrets, string $sourcePbxId): array
    {
        $number = $operation['object']['identity'];
        $type = $operation['object']['type'];
        if ($type === 'ringgroup') {
            $parameters = $this->groups->read($number);
            if ($parameters === null) { return ['state' => 'absent']; }
            unset($parameters['number']);
            return ['state' => 'present', 'parameters' => $parameters, 'secretMatches' => true];
        }
        $snapshot = ($this->snapshot)($type, $number);
        if (!$snapshot['row']) {
            $hasAst = array_filter($snapshot['ast'] ?? [], static fn ($value): bool => (string) $value !== '');
            return ['state' => ($hasAst || !empty($snapshot['settings'])) ? 'partial' : 'absent'];
        }
        if ($type === 'user') {
            return ['state' => 'present', 'parameters' => NativeProfile::userParameters($snapshot, $number), 'secretMatches' => true];
        }
        $row = $snapshot['row'];
        if (($row['tech'] ?? '') !== 'pjsip' || ($row['devicetype'] ?? '') !== 'fixed'
            || (string) ($row['user'] ?? '') !== $number || ($row['dial'] ?? '') !== 'PJSIP/' . $number
            || (string) ($row['emergency_cid'] ?? '') !== '' || (string) ($row['hint_override'] ?? '') !== '') {
            throw new RuntimeException('DEVICE_RELATIONSHIP_UNSUPPORTED');
        }
        if (array_diff(array_keys($row), ['id','tech','dial','devicetype','user','description','emergency_cid','hint_override'])) {
            throw new RuntimeException('DEVICE_METADATA_UNSUPPORTED');
        }
        foreach (['user' => $number, 'tech' => 'pjsip', 'dial' => 'PJSIP/' . $number, 'type' => 'fixed', 'default_user' => $number] as $key => $expected) {
            if (($snapshot['ast'][$key] ?? '') !== $expected) { return ['state' => 'partial']; }
        }
        $settings = $snapshot['settings'];
        if (!$settings || empty($settings['secret'])) { return ['state' => 'partial']; }
        $description = (string) $row['description'];
        $fixed = NativeProfile::deviceFixed($number, $description);
        foreach ($fixed as $key => $expected) {
            if ((string) ($settings[$key] ?? '') !== $expected) { throw new RuntimeException('PJSIP_PROFILE_UNSUPPORTED'); }
        }
        $selected = [];
        foreach (['context','transport','dtmfmode','allow','disallow','direct_media','rtp_symmetric','force_rport',
            'rewrite_contact','max_contacts','remove_existing','media_encryption'] as $key) {
            if (!array_key_exists($key, $settings)) { throw new RuntimeException('PJSIP_FIELD_MISSING'); }
            $selected[$key] = $key === 'max_contacts' ? (int) $settings[$key] : (string) $settings[$key];
        }
        $allowed = array_merge(array_keys($fixed), array_keys($selected), ['secret','username']);
        if (array_diff(array_keys($settings), $allowed)) { throw new RuntimeException('PJSIP_UNKNOWN_FIELD'); }
        BuildSpec::settings($selected);
        $parameters = ['user' => $number, 'description' => $description,
            'authUsername' => (string) ($settings['username'] ?? $settings['account']), 'settings' => $selected];
        $reference = $operation['secretRefs']['authPassword'];
        $matches = hash_equals((string) $settings['secret'], $secrets->resolve($reference, $sourcePbxId));
        unset($settings['secret']);
        return ['state' => 'present', 'parameters' => $parameters, 'secretMatches' => $matches];
    }

    public function create(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        $bufferLevel = ob_get_level();
        ob_start(static fn (string $output): string => '');
        try {
            $this->mutate($operation, $secrets, $sourcePbxId);
        } finally {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
        }
    }

    private function mutate(array $operation, SecretResolver $secrets, string $sourcePbxId): void
    {
        $number = $operation['object']['identity'];
        $parameters = $operation['parameters'];
        if ($operation['adapter'] === 'core.user.v1') {
            $settings = $this->core->generateDefaultUserSettings($number, $parameters['name']);
            $settings['voicemail'] = 'novm';
            $settings['cfringtimer'] = '0';
            $settings['concurrency_limit'] = '0';
            if ($this->core->addUser($number, $settings, false) !== true) { throw new RuntimeException('CORE_USER_WRITE_UNCONFIRMED'); }
        } elseif ($operation['adapter'] === 'core.pjsip.v1') {
            $flat = NativeProfile::deviceFixed($number, $parameters['description']) + $parameters['settings'];
            $flat['username'] = $parameters['authUsername'];
            $flat['secret'] = $secrets->resolve($operation['secretRefs']['authPassword'], $sourcePbxId);
            $flat += ['devicetype' => 'fixed', 'user' => $parameters['user'], 'description' => $parameters['description'],
                'emergency_cid' => '', 'hint_override' => ''];
            $settings = []; $flag = 2;
            foreach ($flat as $key => $value) { $settings[$key] = ['value' => (string) $value, 'flag' => $flag++]; }
            try {
                if ($this->core->addDevice($number, 'pjsip', $settings, false) !== true) { throw new RuntimeException('CORE_DEVICE_WRITE_UNCONFIRMED'); }
            } finally { unset($flat['secret'], $settings['secret']); }
        } elseif ($operation['adapter'] === 'ringgroups.v1') {
            $this->groups->create(['number' => $number] + $parameters);
        } else { throw new RuntimeException('UNSUPPORTED_NATIVE_MUTATION'); }
    }

    public function export(string $sourcePbxId, array $groupNumbers, SecretResolver $secrets): array
    {
        $groups = []; $members = [];
        foreach ($groupNumbers as $number) {
            if (!is_string($number) || !preg_match('/^[0-9]{2,8}$/D', $number)) { throw new RuntimeException('INVALID_GROUP_SELECTION'); }
            $group = $this->groups->read($number);
            if ($group === null) { throw new RuntimeException('SOURCE_GROUP_MISSING'); }
            $groups[] = $group;
            $members = array_merge($members, $group['members']);
        }
        $users = []; $devices = [];
        foreach (array_unique($members) as $member) {
            $userOperation = BuildSpec::instruction('core.user.v1', $member, [], [], []);
            $user = $this->inspect($userOperation, $secrets, $sourcePbxId);
            if ($user['state'] !== 'present') { throw new RuntimeException('SOURCE_USER_INCOMPLETE'); }
            $reference = $secrets->reference($sourcePbxId, $member);
            $deviceOperation = BuildSpec::instruction('core.pjsip.v1', $member, [], ['user:' . $member], ['authPassword' => $reference]);
            $device = $this->inspect($deviceOperation, $secrets, $sourcePbxId);
            if ($device['state'] !== 'present' || !$device['secretMatches']) { throw new RuntimeException('SOURCE_DEVICE_OR_SECRET_INCOMPLETE'); }
            $users[$member] = $user['parameters'];
            $devices[$member] = $device['parameters'] + ['secretRef' => $reference];
        }
        return BuildSpec::create($sourcePbxId, $this->environment()['modules'], $users, $devices, $groups);
    }

    public function apply(): array
    {
        $bufferLevel = ob_get_level();
        ob_start(static fn (string $output): string => '');
        try {
            if (($this->applyRunner)() !== 0) { throw new RuntimeException('FREEPBX_RELOAD_UNCONFIRMED'); }
        } finally {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
        }
        return ['mode' => 'FREEPBX_UNVERIFIED_INTEGRATION', 'generation' => 'succeeded', 'reload' => 'succeeded',
            'runtime' => 'reload_reported_not_independently_verified'];
    }
}