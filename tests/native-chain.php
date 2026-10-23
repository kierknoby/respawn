<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\DesiredStatePlanner;
use Respawn\Reconstruction\FreePbxBuildTarget;
use Respawn\Reconstruction\FreePbxRinggroups;
use Respawn\Reconstruction\MemorySecrets;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

final class CoreContractDouble
{
    public array $users = [];
    public array $devices = [];
    public bool $partialDevice = false;
    public bool $printUntrustedOutput = false;
    public function generateDefaultUserSettings($number, $name): array { return ['name' => $name, 'ringtimer' => 0, 'voicemail' => 'novm']; }
    public function addUser($number, $settings, $edit = false): bool
    {
        if (isset($this->users[$number])) { throw new RuntimeException('Duplicate user.'); }
        $this->users[$number] = ['row' => ['extension' => $number, 'name' => $settings['name'], 'voicemail' => $settings['voicemail'],
            'password' => '', 'ringtimer' => '0', 'noanswer' => '', 'recording' => '', 'outboundcid' => '', 'sipname' => '',
            'noanswer_cid' => '', 'busy_cid' => '', 'chanunavail_cid' => '', 'noanswer_dest' => '', 'busy_dest' => '', 'chanunavail_dest' => ''],
            'ast' => ['cidname' => $settings['name'], 'cidnum' => $number, 'device' => $number, 'voicemail' => 'novm',
                'ringtimer' => '0', 'cfringtimer' => (string) $settings['cfringtimer'], 'concurrency_limit' => (string) $settings['concurrency_limit'], 'noanswer' => '', 'outboundcid' => '', 'password' => '', 'accountcode' => '',
                'recording/in/external' => 'dontcare', 'recording/out/external' => 'dontcare',
                'recording/in/internal' => 'dontcare', 'recording/out/internal' => 'dontcare',
                'recording/ondemand' => 'disabled', 'recording/priority' => '10', 'screen' => '', 'pinless' => '',
                'answermode' => 'disabled', 'intercom' => 'enabled', 'rvolume' => '', 'cwtone' => 'disabled', 'dialopts' => ''], 'callwaiting' => 'ENABLED'];
        return true;
    }
    public function addDevice($number, $tech, $settings, $edit = false): bool
    {
        if ($this->printUntrustedOutput) { echo 'UNTRUSTED-UPSTREAM-OUTPUT'; }
        if ($tech !== 'pjsip' || $edit || isset($this->devices[$number])) { throw new RuntimeException('Invalid native device create.'); }
        $flat = array_map(static fn (array $setting): string => (string) $setting['value'], $settings);
        $this->devices[$number] = ['row' => ['id' => $number, 'tech' => 'pjsip', 'dial' => $flat['dial'],
            'devicetype' => $flat['devicetype'], 'user' => $flat['user'], 'description' => $flat['description'],
            'emergency_cid' => $flat['emergency_cid'], 'hint_override' => $flat['hint_override']],
            'settings' => [], 'ast' => ['user' => $flat['user'], 'tech' => 'pjsip', 'dial' => $flat['dial'],
                'type' => $flat['devicetype'], 'default_user' => $flat['defaultuser'] ?: $flat['user']]];
        if ($this->partialDevice) { throw new RuntimeException('Simulated SIP storage outage after Core metadata write.'); }
        foreach (['devicetype','user','description','emergency_cid','changecdriver','hint_override'] as $key) { unset($flat[$key]); }
        if ($flat['max_contacts'] === '1') { $flat['remove_existing'] = 'yes'; }
        $this->devices[$number]['settings'] = $flat;
        return true;
    }
    public function snapshot(string $type, string $number): array
    {
        return $type === 'user' ? ($this->users[$number] ?? ['row' => null, 'ast' => [], 'callwaiting' => ''])
            : ($this->devices[$number] ?? ['row' => null, 'ast' => [], 'settings' => []]);
    }
}

function nativeDouble(string $identity, bool $applyAvailable = true): array {
    $core = new CoreContractDouble();
    $ringgroups = new class {
        public array $rows = [];
        public function get($number): array { return $this->rows[$number] ?? []; }
        public function add(...$args): bool {
            if (isset($this->rows[$args[0]])) { return false; }
            $keys = ['grpnum','strategy','grptime','grplist','postdest','description','grppre','annmsg_id','alertinfo','needsconf',
                'remotealert_id','toolate_id','ringing','cwignore','cfignore','changecid','fixedcid','cpickup','recording','progress','elsewhere','rvolume'];
            $this->rows[$args[0]] = array_combine($keys, array_slice($args, 0, 22));
            return true;
        }
    };
    $groups = new FreePbxRinggroups($ringgroups, $identity, fn (string $number): bool => isset($core->users[$number]));
    $applies = new ArrayObject(['count' => 0]);
    $target = new FreePbxBuildTarget($core, $groups, $identity, $core->snapshot(...),
        fn (): array => ['modules' => SimulatedPbx::moduleVersions(), 'capabilities' => array_keys(BuildSpec::ADAPTERS), 'mode' => 'CONTRACT_DOUBLE'],
        function () use ($applies): int { $applies['count']++; return 0; }, static function (array $operations): void {}, $applyAvailable);
    return [$target, $core, $ringgroups, $applies];
}

$root = sys_get_temp_dir() . '/respawn-native-' . bin2hex(random_bytes(6));
try {
    $document = SimulatedPbx::blueprint('pbx-alpha');
    $values = [];
    foreach (BuildSpec::plan($document) as $operation) { foreach ($operation['secretRefs'] as $reference) { $values[$reference] = bin2hex(random_bytes(24)); } }
    $secrets = new MemorySecrets($values);
    [$source, $sourceCore] = nativeDouble('source');
    foreach (BuildSpec::plan($document) as $operation) { $source->create($operation, $secrets, 'pbx-alpha'); }
    $export = $source->export('pbx-alpha', ['600','601'], $secrets);
    [$target, $targetCore, $groups, $applies] = nativeDouble('target');
    $targetCore->printUntrustedOutput = true;
    ob_start();
    $result = (new BuildExecutor($target, new JsonStateStore($root), $secrets))->execute($export, true);
    $captured = ob_get_clean();
    if ($captured !== '') { throw new RuntimeException('Raw module output reached the caller.'); }
    if ($result['state'] !== 'configuration_verified' || $applies['count'] !== 1) { throw new RuntimeException('Native contract chain/apply did not complete.'); }
    if ($target->export('pbx-alpha', ['600','601'], $secrets) !== $export) { throw new RuntimeException('Native source/target export mismatch.'); }
    $nativeSnapshot = $target->reconciliationSnapshot($secrets, 'pbx-alpha', \Respawn\Reconstruction\RevisionStore::operationCatalog($export));
    if (count($nativeSnapshot) !== count(BuildSpec::plan($export))) { throw new RuntimeException('Native reconciliation snapshot omitted selected objects.'); }
    foreach ($nativeSnapshot as $entry) {
        if (($entry['owner'] ?? '') !== 'native_ownership_unverified' || empty($entry['shared'])) {
            throw new RuntimeException('Native reconciliation inferred ownership.');
        }
    }
    $nativeCapabilities = $target->reconciliationCapabilities();
    if ($nativeCapabilities['update'] || $nativeCapabilities['remove'] || $nativeCapabilities['revert']) {
        throw new RuntimeException('Native adapter unexpectedly enables unsupported revision mutations.');
    }
    $removedBlueprint = \Respawn\Reconstruction\BuildSpec::create('pbx-alpha', $export['modules'],
        ['1001' => ['name' => 'Alpha 1001', 'voicemail' => 'novm']],
        ['1001' => ['user' => '1001', 'description' => 'Alpha 1001', 'authUsername' => 'Alpha-1001',
            'settings' => BuildSpec::plan($export)[2]['parameters']['settings'], 'secretRef' => 'secret:pbx-alpha:1001:v1']],
        [['number' => '600', 'description' => 'Alpha primary', 'strategy' => 'ringall', 'timeout' => 20,
            'members' => ['1001'], 'destination' => 'hangup']]);
    $nativePlan = DesiredStatePlanner::plan($removedBlueprint, $nativeSnapshot,
        ['complete' => true, 'objectIds' => array_column(BuildSpec::plan($export), 'id')], 'target', 'rev-' . str_repeat('a', 32),
        'regenerate', $nativeCapabilities, $target->managedOwner());
    if (!array_filter($nativePlan['conflicts'], static fn (array $item): bool => in_array($item['reasonCode'] ?? '',
            ['TARGET_CONFIGURATION_CONFLICT','REMOVAL_OWNERSHIP_UNVERIFIED'], true))) {
        throw new RuntimeException('Native shared objects were not blocked from reconciliation/removal.');
    }
    [$noApplyTarget, $noApplyCore] = nativeDouble('no-apply-target', false);
    $noApplyCapabilities = $noApplyTarget->reconciliationCapabilities();
    if ($noApplyCapabilities['apply'] || $noApplyCapabilities['update'] || $noApplyCapabilities['remove']) {
        throw new RuntimeException('Native target without an apply context advertises executable capabilities.');
    }
    $emptySnapshot = $noApplyTarget->reconciliationSnapshot($secrets, 'pbx-alpha',
        \Respawn\Reconstruction\RevisionStore::operationCatalog($export));
    $createPlan = DesiredStatePlanner::plan($export, $emptySnapshot,
        ['complete' => true, 'objectIds' => array_column(BuildSpec::plan($export), 'id')], 'no-apply-target',
        'rev-' . str_repeat('b', 32), 'regenerate', $noApplyCapabilities, $noApplyTarget->managedOwner());
    try {
        (new BuildExecutor($noApplyTarget, new JsonStateStore($root . '/no-apply'), $secrets))
            ->executeDesiredState($export, $createPlan, $createPlan['planId'], true);
        throw new LogicException('Native execution without apply context was accepted.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'TARGET_APPLY_UNSUPPORTED') { throw $exception; }
    }
    if ($noApplyCore->users || $noApplyCore->devices) { throw new RuntimeException('Native writes occurred before the apply gate.'); }
    echo "PASS: native target keeps updates/removals and shared ownership blocked; missing apply context prevents writes.\n";
    foreach (['1001','1002'] as $number) {
        $saved = $targetCore->devices[$number];
        if ($saved['row']['user'] !== $number || $saved['settings']['account'] !== $number
            || $saved['settings']['username'] !== 'Alpha-' . $number
            || !hash_equals($values['secret:pbx-alpha:' . $number . ':v1'], $saved['settings']['secret'])
            || $saved['settings']['context'] !== 'from-internal' || $saved['settings']['allow'] !== 'ulaw,alaw'
            || $saved['settings']['max_contacts'] !== '1') { throw new RuntimeException('Continuity identity/settings/secret not preserved.'); }
    }
    if ($groups->rows['600']['postdest'] !== 'ext-group,601,1' || $groups->rows['601']['grplist'] !== '1002') {
        throw new RuntimeException('Real Ringgroups destination/member encoding mismatch.');
    }
    echo "PASS: native adapter contract preserves users, fixed devices, auth identity/credentials, PJSIP settings, group references and one final apply.\n";
    $sourceCore->devices['1001']['settings']['unrecognised_nonempty_setting'] = 'yes';
    try { $source->export('pbx-alpha', ['600','601'], $secrets); throw new LogicException('Unsupported field accepted.'); }
    catch (RuntimeException $exception) { if ($exception instanceof LogicException) { throw $exception; } }
    echo "PASS: source native adapter refuses unsupported PJSIP configuration.\n";
    [$broken, $brokenCore] = nativeDouble('partially-written-target');
    $brokenCore->partialDevice = true;
    $brokenExecutor = new BuildExecutor($broken, new JsonStateStore($root . '/partial'), $secrets);
    $partial = $brokenExecutor->execute($document, true);
    if ($partial['state'] !== 'unknown' || !isset($brokenCore->devices['1001'])
        || $brokenCore->devices['1001']['settings'] !== []) { throw new RuntimeException('Core partial device mutation was not preserved as unknown.'); }
    $retry = $brokenExecutor->execute($document, true);
    if ($retry['state'] !== 'failed' || $retry['error']['code'] !== 'PARTIAL_TARGET_REQUIRES_RECONCILIATION'
        || count($brokenCore->devices) !== 1) { throw new RuntimeException('Partial Core device was blindly recreated.'); }
    echo "PASS: Core metadata/AstDB accepted before SIP write failure stays unknown; retry blocks partial device without duplicates.\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    }
    if (is_dir($root)) { rmdir($root); }
}