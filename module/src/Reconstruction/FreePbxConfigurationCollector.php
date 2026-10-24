<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use PDO;
use RuntimeException;
use Throwable;

final class FreePbxConfigurationCollector
{
    private const USER_FIELDS = ['extension','name','voicemail','ringtimer','noanswer','recording','outboundcid',
        'sipname','noanswer_cid','busy_cid','chanunavail_cid','noanswer_dest','busy_dest','chanunavail_dest','mohclass'];
    private const USER_AST_FIELDS = ['cidname','cidnum','device','voicemail','ringtimer','cfringtimer',
        'concurrency_limit','noanswer','outboundcid','accountcode','recording/in/external','recording/out/external',
        'recording/in/internal','recording/out/internal','recording/ondemand','recording/priority','screen','pinless',
        'answermode','intercom','rvolume','cwtone','dialopts'];
    private const DEVICE_FIELDS = ['id','tech','dial','devicetype','user','description','emergency_cid','hint_override'];
    private const DEVICE_AST_FIELDS = ['user','tech','dial','type','default_user'];
    private const PJSIP_FIELDS = ['sipdriver','account','dial','callerid','mailbox','trustrpid','sendrpid','qualifyfreq',
        'avpf','timers','icesupport','send_connected_line','user_eq_phone','namedcallgroup','namedpickupgroup',
        'accountcode','media_use_received_transport','media_address','mwi_subscription','aggregate_mwi',
        'media_encryption_optimistic','device_state_busy_at','rtcp_mux','bundle','minimum_expiration',
        'maximum_expiration','match','rtp_timeout','rtp_timeout_hold','timers_min_se','force_callerid',
        'max_audio_streams','max_video_streams','webrtc','defaultuser','message_context','refer_blind_progress',
        'vmexten','outbound_auth','outbound_proxy','context','transport','dtmfmode','allow','disallow','direct_media',
        'rtp_symmetric','force_rport','rewrite_contact','max_contacts','remove_existing','media_encryption','username'];
    private const GROUP_FIELDS = ['grpnum','description','strategy','grptime','grplist','postdest','grppre',
        'annmsg_id','alertinfo','needsconf','remotealert_id','toolate_id','ringing','cwignore','cfignore','cpickup',
        'recording','progress','elsewhere','rvolume','changecid','fixedcid'];
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
        $this->deviceStates = [];
        $this->deviceDiscoverySucceeded = false;
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
                $user = $snapshot->read('user', $number);
                if (!is_array($user['row'] ?? null) || (string) ($user['row']['extension'] ?? '') !== $number) {
                    throw new RuntimeException('USER_CAPTURE_IDENTITY_INVALID');
                }
                $fields = ['row'=>$this->captureFields($user['row'], self::USER_FIELDS, ['password'],
                    ['extension','name','voicemail']),
                    'ast'=>$this->captureFields($user['ast'], self::USER_AST_FIELDS, ['password']),
                    'callwaiting'=>$user['callwaiting']];
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
        if ((string) ($row['id'] ?? '') !== $number || ($row['tech'] ?? '') !== 'pjsip'
            || ($row['devicetype'] ?? '') !== 'fixed' || (string) ($row['user'] ?? '') !== $number
            || ($row['dial'] ?? '') !== 'PJSIP/' . $number) {
            throw new RuntimeException('DEVICE_RELATIONSHIP_UNSUPPORTED');
        }
        foreach (['user'=>$number,'tech'=>'pjsip','dial'=>'PJSIP/' . $number,'type'=>'fixed','default_user'=>$number] as $key=>$expected) {
            if (($snapshot['ast'][$key] ?? '') !== $expected) { throw new RuntimeException('DEVICE_AST_STATE_INCOMPLETE'); }
        }
        return ['row'=>$this->captureFields($row, self::DEVICE_FIELDS, [],
                ['id','tech','dial','devicetype','user','description']),
            'settings'=>$this->captureFields($snapshot['settings'] ?? [], self::PJSIP_FIELDS,
                ['secret','md5_cred','secret_origional'], ['sipdriver','account','dial','context']),
            'ast'=>$this->captureFields($snapshot['ast'], self::DEVICE_AST_FIELDS)];
    }

    private function collectGroups(): array
    {
        if (!method_exists($this->freePbx->Ringgroups, 'get')) { throw new RuntimeException('RINGGROUPS_INTERFACE_UNAVAILABLE'); }
        $statement = $this->database()->query('SELECT grpnum FROM ringgroups ORDER BY grpnum');
        if (!$statement) { throw new RuntimeException('PUDDLE_RINGGROUP_DISCOVERY_FAILED'); }
        $numbers = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($numbers)) { throw new RuntimeException('PUDDLE_RINGGROUP_DISCOVERY_FAILED'); }
        $objects = [];
        foreach ($numbers as $rawNumber) {
            $number = (string) $rawNumber;
            if (!preg_match('/^[0-9]{2,8}$/D', $number)) { throw new RuntimeException('PUDDLE_RINGGROUP_IDENTITY_INVALID'); }
            try {
                $group = $this->freePbx->Ringgroups->get($number);
                if (!is_array($group) || (string) ($group['grpnum'] ?? '') !== $number) {
                    throw new RuntimeException('SOURCE_GROUP_READBACK_MISSING');
                }
                $group = $this->captureFields($group, self::GROUP_FIELDS, [],
                    ['grpnum','description','strategy','grptime','grplist','postdest']);
                $objects[] = ['id'=>$number,'type'=>'ringgroups.v1','state'=>'captured','fields'=>$group];
            } catch (Throwable $exception) {
                $objects[] = ['id'=>$number,'type'=>'ringgroups.v1','state'=>'unsupported',
                    'fields'=>['reasonCode'=>$this->safeCode($exception->getMessage(), 'RINGGROUP_CONFIGURATION_UNSUPPORTED')]];
            }
        }
        return $objects;
    }

    private function captureFields(array $source, array $allowed, array $secrets = [], array $required = []): array
    {
        if (array_diff(array_keys($source), array_merge($allowed, $secrets))) {
            throw new RuntimeException('PUDDLE_CAPTURE_UNKNOWN_FIELD');
        }
        if (array_diff($required, array_keys($source))) { throw new RuntimeException('PUDDLE_CAPTURE_FIELD_MISSING'); }
        $fields = array_intersect_key($source, array_fill_keys($allowed, true));
        foreach ($fields as $value) {
            if (!(is_string($value) || is_bool($value) || $value === null
                || (is_int($value) && abs($value) <= 9007199254740991))) {
                throw new RuntimeException('PUDDLE_CAPTURE_VALUE_UNSUPPORTED');
            }
            if (is_string($value) && preg_match('~(?:[a-z][a-z0-9+.-]*://|sips?:)[^\s/@]*:[^\s/@]*@~i', $value)) {
                throw new RuntimeException('PUDDLE_CAPTURE_SECRET_URI_UNSUPPORTED');
            }
        }
        json_encode($fields, JSON_THROW_ON_ERROR);
        return $fields;
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