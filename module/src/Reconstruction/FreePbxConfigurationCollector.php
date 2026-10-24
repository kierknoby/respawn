<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use PDO;
use RuntimeException;
use Throwable;

final class FreePbxConfigurationCollector
{
    private array $deviceStates = [];
    private bool $deviceDiscoverySucceeded = false;
    private array $additionalModules;
    private array $additionalProviders;

    public function __construct(private readonly object $freePbx, private readonly string $sourcePbxId,
        private readonly string $secretManifest = '', array $additionalModules = [], array $additionalProviders = [])
    {
        $this->additionalModules = $additionalModules;
        $this->additionalProviders = $additionalProviders;
    }

    public function capture(): array
    {
        $providers = [
            'core.users' => ['modules'=>['core'], 'collect'=>fn (): array => $this->collectUsers()],
            'core.pjsip' => ['modules'=>['core','sipsettings'], 'collect'=>fn (): array => $this->collectDevices()],
            'ringgroups' => ['modules'=>['ringgroups'], 'collect'=>fn (): array => $this->collectGroups()],
        ];
        foreach ($this->additionalProviders as $name=>$provider) {
            if (isset($providers[$name])) { throw new RuntimeException('PUDDLE_PROVIDER_REGISTRY_DUPLICATE'); }
            $providers[$name] = $provider;
        }
        $moduleNames = array_merge(['framework','core','ringgroups','sipsettings'], array_keys($this->additionalModules));
        foreach ($providers as $provider) {
            if (!is_array($provider)) { throw new RuntimeException('PUDDLE_PROVIDER_REGISTRY_INVALID'); }
            $required = $provider['modules'] ?? (isset($provider['module']) ? [$provider['module']] : null);
            if (!is_array($required) || !$required || !array_is_list($required)) {
                throw new RuntimeException('PUDDLE_PROVIDER_REGISTRY_INVALID');
            }
            foreach ($required as $module) {
                if (!is_string($module) || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $module)) {
                    throw new RuntimeException('PUDDLE_PROVIDER_REGISTRY_INVALID');
                }
                $moduleNames[] = $module;
            }
        }
        $moduleNames = array_values(array_unique($moduleNames));
        $modules = $this->modules($moduleNames);
        $capture = (new PuddleCollector($this->sourcePbxId))->capture($modules, $providers, $this->credentialStatus(...));
        $version = $modules['framework']['version'] ?? null;
        if (!is_string($version) || !preg_match('/^17\./', $version)) {
            $gaps = $capture['blockingGaps'];
            $gaps[] = 'UNSUPPORTED_FREEPBX_VERSION';
            $capture = ConfigurationCapture::create($this->sourcePbxId, $modules, $capture['collections'],
                $capture['credentials'], $gaps);
        }
        return $capture;
    }

    private function modules(array $moduleNames): array
    {
        $result = [];
        foreach ($moduleNames as $name) {
            try {
                $available = (bool) $this->freePbx->Modules->checkStatus($name);
                $info = $this->freePbx->Modules->getInfo($name);
                $moduleInfo = $info[$name] ?? [];
                $result[$name] = ['available'=>$available,
                    'version'=>is_string($moduleInfo['version'] ?? null) ? $moduleInfo['version'] : null,
                    'discoveryState'=>'known'];
            } catch (Throwable) {
                $result[$name] = ['available'=>false,'version'=>null,'discoveryState'=>'unknown'];
            }
        }
        return $result;
    }

    private function database(): PDO
    {
        if (!isset($this->freePbx->Database) || !$this->freePbx->Database instanceof PDO) {
            throw new RuntimeException('PUDDLE_CONFIGURATION_DATABASE_UNAVAILABLE');
        }
        return $this->freePbx->Database;
    }

    private function userNumbers(): array
    {
        if (!method_exists($this->freePbx->Core, 'listUsers')) { throw new RuntimeException('PUDDLE_CORE_INTERFACE_UNAVAILABLE'); }
        $users = $this->freePbx->Core->listUsers();
        if (!is_array($users)) { throw new RuntimeException('PUDDLE_CORE_LIST_INVALID'); }
        $numbers = [];
        foreach ($users as $user) {
            $number = is_array($user) ? ($user[0] ?? $user['extension'] ?? null) : null;
            if (!is_string($number) || !preg_match('/^[0-9]{2,8}$/D', $number)) {
                throw new RuntimeException('PUDDLE_CORE_IDENTITY_INVALID');
            }
            $numbers[] = $number;
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers, SORT_STRING);
        return $numbers;
    }

    private function snapshot(): FreePbxSnapshot
    {
        return new FreePbxSnapshot($this->database(), $this->freePbx->astman);
    }

    private function collectUsers(): array
    {
        $snapshot = $this->snapshot();
        $objects = [];
        foreach ($this->userNumbers() as $number) {
            try {
                $fields = NativeProfile::userParameters($snapshot->read('user', $number), $number);
                $objects[] = ['id'=>$number,'type'=>'core.user.v1','state'=>'captured','fields'=>$fields];
            } catch (Throwable $exception) {
                $objects[] = ['id'=>$number,'type'=>'core.user.v1','state'=>'unsupported',
                    'fields'=>['reasonCode'=>$this->safeCode($exception->getMessage(), 'USER_CONFIGURATION_UNSUPPORTED')]];
            }
        }
        return $objects;
    }

    private function collectDevices(): array
    {
        $statement = $this->database()->query("SELECT id FROM devices WHERE tech = 'pjsip' ORDER BY id");
        if (!$statement) { throw new RuntimeException('PUDDLE_PJSIP_DISCOVERY_FAILED'); }
        $numbers = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($numbers)) { throw new RuntimeException('PUDDLE_PJSIP_DISCOVERY_FAILED'); }
        $this->deviceDiscoverySucceeded = true;
        $snapshot = $this->snapshot();
        $objects = [];
        foreach ($numbers as $rawNumber) {
            $number = (string) $rawNumber;
            if (!preg_match('/^[0-9]{2,8}$/D', $number)) {
                throw new RuntimeException('PUDDLE_PJSIP_IDENTITY_INVALID');
            }
            try {
                $device = $snapshot->read('pjsip', $number);
                $fields = $this->pjsipFields($device, $number);
                $objects[] = ['id'=>$number,'type'=>'core.pjsip.v1','state'=>'captured','fields'=>$fields];
                $this->deviceStates[$number] = is_string($device['settings']['secret'] ?? null)
                    ? $device['settings']['secret'] : null;
            } catch (Throwable $exception) {
                $objects[] = ['id'=>$number,'type'=>'core.pjsip.v1','state'=>'unsupported',
                    'fields'=>['reasonCode'=>$this->safeCode($exception->getMessage(), 'PJSIP_CONFIGURATION_UNSUPPORTED')]];
                $this->deviceStates[$number] = null;
            }
        }
        return $objects;
    }

    private function pjsipFields(array $snapshot, string $number): array
    {
        $row = $snapshot['row'] ?? null;
        if (!is_array($row)) { throw new RuntimeException('PJSIP_DEVICE_ROW_MISSING'); }
        if (($row['tech'] ?? '') !== 'pjsip' || ($row['devicetype'] ?? '') !== 'fixed'
            || (string) ($row['user'] ?? '') !== $number || ($row['dial'] ?? '') !== 'PJSIP/' . $number
            || (string) ($row['emergency_cid'] ?? '') !== '' || (string) ($row['hint_override'] ?? '') !== '') {
            throw new RuntimeException('DEVICE_RELATIONSHIP_UNSUPPORTED');
        }
        if (array_diff(array_keys($row), ['id','tech','dial','devicetype','user','description','emergency_cid','hint_override'])) {
            throw new RuntimeException('DEVICE_METADATA_UNSUPPORTED');
        }
        foreach (['user'=>$number,'tech'=>'pjsip','dial'=>'PJSIP/' . $number,'type'=>'fixed','default_user'=>$number] as $key=>$expected) {
            if (($snapshot['ast'][$key] ?? '') !== $expected) { throw new RuntimeException('DEVICE_AST_STATE_INCOMPLETE'); }
        }
        $settings = $snapshot['settings'] ?? [];
        $description = (string) ($row['description'] ?? '');
        $fixed = NativeProfile::deviceFixed($number, $description);
        foreach ($fixed as $key=>$expected) {
            if ((string) ($settings[$key] ?? '') !== $expected) { throw new RuntimeException('PJSIP_PROFILE_UNSUPPORTED'); }
        }
        $selected = [];
        foreach (['context','transport','dtmfmode','allow','disallow','direct_media','rtp_symmetric','force_rport',
            'rewrite_contact','max_contacts','remove_existing','media_encryption'] as $key) {
            if (!array_key_exists($key, $settings)) { throw new RuntimeException('PJSIP_FIELD_MISSING'); }
            $selected[$key] = $key === 'max_contacts' ? (int) $settings[$key] : (string) $settings[$key];
        }
        if (array_diff(array_keys($settings), array_merge(array_keys($fixed), array_keys($selected), ['secret','username']))) {
            throw new RuntimeException('PJSIP_UNKNOWN_FIELD');
        }
        BuildSpec::settings($selected);
        $authUsername = $settings['username'] ?? $settings['account'] ?? null;
        if (!is_string($authUsername) || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $authUsername)) {
            throw new RuntimeException('PJSIP_AUTH_IDENTITY_UNSUPPORTED');
        }
        return ['user'=>$number,'description'=>$description,'authUsername'=>$authUsername,'settings'=>$selected];
    }

    private function collectGroups(): array
    {
        if (!method_exists($this->freePbx->Ringgroups, 'get')) { throw new RuntimeException('RINGGROUPS_INTERFACE_UNAVAILABLE'); }
        $statement = $this->database()->query('SELECT grpnum FROM ringgroups ORDER BY grpnum');
        if (!$statement) { throw new RuntimeException('PUDDLE_RINGGROUP_DISCOVERY_FAILED'); }
        $numbers = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($numbers)) { throw new RuntimeException('PUDDLE_RINGGROUP_DISCOVERY_FAILED'); }
        $adapter = new FreePbxRinggroups($this->freePbx->Ringgroups, $this->sourcePbxId,
            static fn (string $number): bool => false);
        $objects = [];
        foreach ($numbers as $rawNumber) {
            $number = (string) $rawNumber;
            if (!preg_match('/^[0-9]{2,8}$/D', $number)) { throw new RuntimeException('PUDDLE_RINGGROUP_IDENTITY_INVALID'); }
            try {
                $group = $adapter->read($number);
                if ($group === null) { throw new RuntimeException('SOURCE_GROUP_READBACK_MISSING'); }
                unset($group['number']);
                $objects[] = ['id'=>$number,'type'=>'ringgroups.v1','state'=>'captured','fields'=>$group];
            } catch (Throwable $exception) {
                $objects[] = ['id'=>$number,'type'=>'ringgroups.v1','state'=>'unsupported',
                    'fields'=>['reasonCode'=>$this->safeCode($exception->getMessage(), 'RINGGROUP_CONFIGURATION_UNSUPPORTED')]];
            }
        }
        return $objects;
    }

    private function credentialStatus(): array
    {
        if (!$this->deviceDiscoverySucceeded) {
            return ['state'=>'incomplete','references'=>[],'recoveryReady'=>false,
                'reasonCode'=>'PJSIP_CONFIGURATION_COLLECTION_INCOMPLETE'];
        }
        if ($this->deviceStates === []) { return ['state'=>'not_required','references'=>[],'recoveryReady'=>false]; }
        if ($this->secretManifest === '' || !is_file($this->secretManifest)) {
            return ['state'=>'not_configured','references'=>[],'recoveryReady'=>false,'reasonCode'=>'SECRET_MANIFEST_MISSING'];
        }
        $resolver = new FileSecrets($this->secretManifest);
        $references = [];
        try {
            foreach ($this->deviceStates as $number=>$sourceSecret) {
                if ($sourceSecret === null) { throw new RuntimeException('SOURCE_CREDENTIAL_UNAVAILABLE'); }
                $number = (string) $number;
                $reference = $resolver->reference($this->sourcePbxId, $number);
                $manifestSecret = $resolver->resolve($reference, $this->sourcePbxId);
                if (!hash_equals($sourceSecret, $manifestSecret)) { throw new RuntimeException('SECRET_REFERENCE_MISMATCH'); }
                $references[] = $reference;
            }
            return ['state'=>'verified_locally','references'=>$references,'recoveryReady'=>false];
        } catch (Throwable $exception) {
            return ['state'=>'incomplete','references'=>$references,'recoveryReady'=>false,
                'reasonCode'=>$this->safeCode($exception->getMessage(), 'CREDENTIAL_PROTECTION_UNAVAILABLE')];
        }
    }

    private function safeCode(string $message, string $fallback): string
    {
        return preg_match('/^[A-Z0-9_]{3,64}$/D', $message) ? $message : $fallback;
    }
}