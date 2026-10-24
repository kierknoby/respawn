<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\ConfigurationCapture;
use Respawn\Reconstruction\FreePbxConfigurationCollector;
use Respawn\Reconstruction\NativeProfile;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function puddleCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}

final class PuddleTestCore
{
    public function __construct(private array $users, private bool $fail = false) {}
    public function listUsers(): array
    {
        if ($this->fail) { throw new RuntimeException('PUDDLE_CORE_PROVIDER_FAILED'); }
        return array_map(static fn (string $number): array => [$number], $this->users);
    }
}

final class PuddleTestModules
{
    public function __construct(private array $availability = [], private string $frameworkVersion = '17.0.1') {}
    public function checkStatus(string $name): bool { return $this->availability[$name] ?? true; }
    public function getInfo(string $name): array
    { return [$name=>['version'=>($name === 'framework' ? $this->frameworkVersion : '17.0.2')]]; }
}

final class PuddleTestManager
{
    public function connected(): bool { return true; }
    public function database_get(string $family, string $key): string
    {
        [$number,$item] = array_pad(explode('/', $key, 2), 2, '');
        if ($family === 'CW') { return 'ENABLED'; }
        if ($family === 'AMPUSER') { return (string) (NativeProfile::userAst($number, 'Test ' . $number)[$item] ?? ''); }
        $ast = ['user'=>$number,'tech'=>'pjsip','dial'=>'PJSIP/' . $number,'type'=>'fixed','default_user'=>$number];
        return (string) ($ast[$item] ?? '');
    }
}

final class PuddleTestRinggroups
{
    public function __construct(public array $rows = [], public bool $failGet = false) {}
    public function get(string $number): array
    {
        if ($this->failGet) { throw new RuntimeException('RINGGROUP_READ_FAILED'); }
        return $this->rows[$number] ?? [];
    }
    public function add(): bool { return false; }
}

function puddleFixture(string $root, array $groups = [], array $users = ['1001'], array $availability = [], bool $failUsers = false,
    string $frameworkVersion = '17.0.1'): array
{
    $database = new PDO('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('CREATE TABLE users (extension TEXT, name TEXT, voicemail TEXT, password TEXT, ringtimer TEXT,
        noanswer TEXT, recording TEXT, outboundcid TEXT, sipname TEXT, noanswer_cid TEXT, busy_cid TEXT,
        chanunavail_cid TEXT, noanswer_dest TEXT, busy_dest TEXT, chanunavail_dest TEXT);
        CREATE TABLE devices (id TEXT, tech TEXT, dial TEXT, devicetype TEXT, user TEXT, description TEXT,
        emergency_cid TEXT, hint_override TEXT);
        CREATE TABLE sip (id TEXT, keyword TEXT, data TEXT);
        CREATE TABLE ringgroups (grpnum TEXT);');
    $userInsert = $database->prepare('INSERT INTO users VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $deviceInsert = $database->prepare('INSERT INTO devices VALUES (?,?,?,?,?,?,?,?)');
    $sipInsert = $database->prepare('INSERT INTO sip VALUES (?,?,?)');
    $sourceSecret = 'synthetic-original-secret-1001';
    foreach ($users as $number) {
        $userInsert->execute([$number,'Test ' . $number,'novm','','0','','','','','','','','','','']);
        $deviceInsert->execute([$number,'pjsip','PJSIP/' . $number,'fixed',$number,'Test ' . $number,'','']);
        $settings = NativeProfile::deviceFixed($number, 'Test ' . $number) + [
            'context'=>'from-internal','transport'=>'','dtmfmode'=>'rfc4733','allow'=>'ulaw,alaw','disallow'=>'all',
            'direct_media'=>'no','rtp_symmetric'=>'yes','force_rport'=>'yes','rewrite_contact'=>'yes',
            'max_contacts'=>1,'remove_existing'=>'yes','media_encryption'=>'no','username'=>'Test-' . $number,
            'secret'=>$sourceSecret];
        foreach ($settings as $key=>$value) { $sipInsert->execute([$number,$key,(string) $value]); }
    }
    $groupRows = [];
    foreach ($groups as $number=>$members) {
        $database->prepare('INSERT INTO ringgroups VALUES (?)')->execute([(string) $number]);
        $groupRows[(string) $number] = ['grpnum'=>(string) $number,'description'=>'Lab group ' . $number,
            'strategy'=>'ringall','grptime'=>'20','grplist'=>implode('-', $members),
            'postdest'=>'app-blackhole,hangup,1','grppre'=>'','annmsg_id'=>'0','alertinfo'=>'','needsconf'=>'',
            'remotealert_id'=>'0','toolate_id'=>'0','ringing'=>'Ring','cwignore'=>'','cfignore'=>'','cpickup'=>'',
            'recording'=>'dontcare','progress'=>'yes','elsewhere'=>'','rvolume'=>'','changecid'=>'default','fixedcid'=>''];
    }
    $freePbx = (object) ['Database'=>$database,'astman'=>new PuddleTestManager(),
        'Core'=>new PuddleTestCore($users,$failUsers),'Modules'=>new PuddleTestModules($availability,$frameworkVersion),
        'Ringgroups'=>new PuddleTestRinggroups($groupRows)];
    return [$freePbx,$sourceSecret];
}

$root = sys_get_temp_dir() . '/respawn-puddle-' . bin2hex(random_bytes(6));
mkdir($root,0700);
try {
    [$freePbx,$secret] = puddleFixture($root);
    $capture = (new FreePbxConfigurationCollector($freePbx,'pbx-puddle'))->capture();
    ConfigurationCapture::verifyHash($capture);
    puddleCheck(count($capture['collections']['ringgroups']['objects']) === 0
        && $capture['collections']['ringgroups']['state'] === 'captured'
        && $capture['credentials']['state'] === 'not_configured'
        && $capture['credentials']['recoveryReady'] === false,
        'no Ring Groups is captured as an empty collection with credentials explicitly incomplete');
    puddleCheck(count($capture['collections']['core.users']['objects']) === 1
        && count($capture['collections']['core.pjsip']['objects']) === 1
        && !str_contains(json_encode($capture), $secret),
        'automatic discovery captures existing user/device structure without storing secret material');

    $store = new RevisionStore(new JsonStateStore($root . '/revisions'));
    $first = $store->captureConfiguration($capture);
    $unchanged = $store->captureConfiguration($capture);
    puddleCheck($first['revisionId'] === $unchanged['revisionId'] && $unchanged['captureResult'] === 'unchanged',
        'first capture creates a local revision and unchanged capture reuses it');
    $legacyBlueprint = SimulatedPbx::blueprint('pbx-puddle');
    $legacyCollections = ['core.users'=>['state'=>'captured','objects'=>[]],
        'core.pjsip'=>['state'=>'captured','objects'=>[]],'ringgroups'=>['state'=>'captured','objects'=>[]]];
    $legacyReferences = [];
    foreach (\Respawn\Reconstruction\BuildSpec::plan($legacyBlueprint) as $operation) {
        $type = $operation['object']['type'];
        $number = $operation['object']['identity'];
        $collection = match ($type) { 'user'=>'core.users', 'pjsip'=>'core.pjsip', default=>'ringgroups' };
        foreach ($operation['secretRefs'] as $reference) { $legacyReferences[] = $reference; }
        $legacyCollections[$collection]['objects'][] = ['id'=>$number,
            'type'=>match ($type) { 'user'=>'core.user.v1', 'pjsip'=>'core.pjsip.v1', default=>'ringgroups.v1' },
            'state'=>'captured','fields'=>$operation['parameters']];
    }
    $legacyModules = [];
    foreach ($legacyBlueprint['modules'] as $name=>$version) {
        $legacyModules[$name] = ['available'=>true,'version'=>$version,'discoveryState'=>'known'];
    }
    $compatibleCapture = ConfigurationCapture::create('pbx-puddle',$legacyModules,$legacyCollections,
        ['state'=>'verified_locally','references'=>$legacyReferences,'recoveryReady'=>false],[]);
    $legacyRevision = $store->capture($legacyBlueprint);
    $compatibleRevision = $store->captureConfiguration($compatibleCapture);
    $compatibilityDiff = $store->compare('pbx-puddle',$legacyRevision['revisionId'],$compatibleRevision['revisionId']);
    puddleCheck($compatibilityDiff['changes']['create'] === [] && $compatibilityDiff['changes']['remove'] === []
        && $compatibilityDiff['changes']['update'] === [] && $compatibilityDiff['captureMetadataChanged'] === true,
        'v2 comparison preserves object identity while exposing separate credential/module capture metadata');
    puddleCheck(count($store->operationCatalogForRevision('pbx-puddle',$legacyRevision['revisionId'])) > 0,
        'v2 execution catalogues ignore adjacent non-executable Puddle snapshot revisions');
    $restartStore = new RevisionStore(new JsonStateStore($root . '/revisions'));
    puddleCheck($restartStore->get('pbx-puddle',$first['revisionId'])['captureKind'] === 'configuration_capture',
        'Puddle capture revision survives a new store instance and validates its integrity hash');
    $user = $capture['collections']['core.users']['objects'][0];
    $device = $capture['collections']['core.pjsip']['objects'][0];
    $v2Modules = [];
    foreach ($capture['modules'] as $name=>$module) { if (!empty($module['available']) && is_string($module['version'])) { $v2Modules[$name] = $module['version']; } }
    $legacyBlueprint = \Respawn\Reconstruction\BuildSpec::create('pbx-puddle',$v2Modules,
        [$user['id']=>$user['fields']],[$device['id']=>$device['fields'] + ['secretRef'=>'secret:pbx-puddle:1001:v1']],[]);
    $legacyRevision = $store->capture($legacyBlueprint);
    $mixedDiff = $store->compare('pbx-puddle',$first['revisionId'],$legacyRevision['revisionId']);
    puddleCheck($mixedDiff['changes'] === ['create'=>[],'update'=>[],'remove'=>[],'unchanged'=>['pjsip:1001','user:1001']]
        && $mixedDiff['captureMetadataChanged'] === true,
        'mixed local-capture/v2 history compares object fields compatibly while reporting credential/module metadata separately');

    [$oneGroupPbx] = puddleFixture($root, ['600'=>['1001']]);
    $oneGroup = (new FreePbxConfigurationCollector($oneGroupPbx,'pbx-puddle'))->capture();
    puddleCheck(count($oneGroup['collections']['ringgroups']['objects']) === 1,
        'one existing Ring Group is discovered without configured group scope');
    [$multipleGroupsPbx] = puddleFixture($root, ['600'=>['1001'],'601'=>['1001']]);
    $multipleGroups = (new FreePbxConfigurationCollector($multipleGroupsPbx,'pbx-puddle'))->capture();
    puddleCheck(count($multipleGroups['collections']['ringgroups']['objects']) === 2,
        'multiple existing Ring Groups are discovered automatically');

    [$emptyPbx] = puddleFixture($root, [], []);
    $empty = (new FreePbxConfigurationCollector($emptyPbx,'pbx-puddle'))->capture();
    puddleCheck($empty['credentials']['state'] === 'not_required'
        && $empty['collections']['core.users']['objects'] === []
        && $empty['collections']['core.pjsip']['objects'] === []
        && $empty['collections']['ringgroups']['objects'] === [],
        'empty supported user, device and Ring Group collections are valid local state');

    $manifestRoot = $root . '/secrets';
    mkdir($manifestRoot,0700);
    file_put_contents($manifestRoot . '/secret','synthetic-original-secret-1001');
    chmod($manifestRoot . '/secret',0600);
    $reference = 'secret:pbx-puddle:1001:v1';
    file_put_contents($manifestRoot . '/manifest.json',json_encode(['sourcePbxId'=>'pbx-puddle',
        'references'=>[$reference=>$manifestRoot . '/secret']],JSON_THROW_ON_ERROR));
    chmod($manifestRoot . '/manifest.json',0600);
    $resolver = new \Respawn\Reconstruction\FileSecrets($manifestRoot . '/manifest.json');
    puddleCheck($resolver->reference('pbx-puddle','1001') === $reference
        && $resolver->resolve($reference,'pbx-puddle') === 'synthetic-original-secret-1001',
        'synthetic protected source manifest resolves the expected reference and value');
    [$configuredPbx] = puddleFixture($root);
    $configured = (new FreePbxConfigurationCollector($configuredPbx,'pbx-puddle',$manifestRoot . '/manifest.json'))->capture();
    puddleCheck($configured['credentials']['state'] === 'verified_locally'
        && $configured['credentials']['recoveryReady'] === false,
        'protected source manifest verifies local credential identity without claiming independent recovery readiness');
    file_put_contents($manifestRoot . '/wrong-secret','synthetic-different-secret-1001');
    chmod($manifestRoot . '/wrong-secret',0600);
    file_put_contents($manifestRoot . '/manifest.json',json_encode(['sourcePbxId'=>'pbx-puddle',
        'references'=>[$reference=>$manifestRoot . '/wrong-secret']],JSON_THROW_ON_ERROR));
    chmod($manifestRoot . '/manifest.json',0600);
    [$mismatchPbx] = puddleFixture($root);
    $mismatch = (new FreePbxConfigurationCollector($mismatchPbx,'pbx-puddle',$manifestRoot . '/manifest.json'))->capture();
    puddleCheck($mismatch['credentials']['state'] === 'incomplete'
        && $mismatch['credentials']['reasonCode'] === 'SECRET_REFERENCE_MISMATCH'
        && $mismatch['credentials']['recoveryReady'] === false,
        'a protected but mismatched credential reference never reports readiness');
    file_put_contents($manifestRoot . '/manifest.json',json_encode(['sourcePbxId'=>'pbx-puddle',
        'references'=>[$reference=>$manifestRoot . '/secret']],JSON_THROW_ON_ERROR));
    chmod($manifestRoot . '/manifest.json',0600);
    [$badPermissionPbx] = puddleFixture($root);
    chmod($manifestRoot . '/manifest.json',0644);
    $badPermission = (new FreePbxConfigurationCollector($badPermissionPbx,'pbx-puddle',$manifestRoot . '/manifest.json'))->capture();
    puddleCheck($badPermission['credentials']['state'] === 'incomplete'
        && $badPermission['credentials']['reasonCode'] === 'SECRET_MANIFEST_UNSAFE',
        'unsafe credential manifest is reported as incomplete without weakening file protections');
    chmod($manifestRoot . '/manifest.json',0600);

    [$noRinggroupModule] = puddleFixture($root,[],['1001'],['ringgroups'=>false]);
    $unavailable = (new FreePbxConfigurationCollector($noRinggroupModule,'pbx-puddle'))->capture();
    puddleCheck($unavailable['collections']['ringgroups']['state'] === 'unavailable'
        && $unavailable['collections']['ringgroups']['reasonCode'] === 'MODULE_UNAVAILABLE',
        'unavailable Ringgroups module differs from an installed module with an empty collection');
    [$noSipSettings] = puddleFixture($root,[],['1001'],['sipsettings'=>false]);
    $noSipSettingsCapture = (new FreePbxConfigurationCollector($noSipSettings,'pbx-puddle'))->capture();
    puddleCheck($noSipSettingsCapture['collections']['core.pjsip']['state'] === 'unavailable'
        && $noSipSettingsCapture['collections']['core.pjsip']['modules'] === ['sipsettings'],
        'PJSIP collection reports its SIP Settings dependency unavailable rather than presenting an empty collection');
    [$unsupportedVersionPbx] = puddleFixture($root,[],[],[],false,'16.0.9');
    $unsupportedVersionCapture = (new FreePbxConfigurationCollector($unsupportedVersionPbx,'pbx-puddle'))->capture();
    puddleCheck(in_array('UNSUPPORTED_FREEPBX_VERSION',$unsupportedVersionCapture['blockingGaps'],true),
        'unsupported FreePBX major version is captured as an explicit blocking gap');
    [$extensiblePbx] = puddleFixture($root,[],[]);
    $extensible = (new FreePbxConfigurationCollector($extensiblePbx,'pbx-puddle','',
        ['voicemail'=>true], ['voicemail.mailboxes'=>['modules'=>['voicemail'],'collect'=>static fn (): array => []]]))->capture();
    puddleCheck(($extensible['modules']['voicemail']['available'] ?? false) === true
        && ($extensible['collections']['voicemail.mailboxes']['state'] ?? '') === 'captured'
        && ($extensible['collections']['voicemail.mailboxes']['objects'] ?? null) === [],
        'a future module provider can be registered without changing the central capture workflow');

    [$failedPbx] = puddleFixture($root,[],['1001'],[],true);
    $failedCapture = (new FreePbxConfigurationCollector($failedPbx,'pbx-puddle'))->capture();
    $failedRevision = $store->captureConfiguration($failedCapture);
    puddleCheck($failedRevision['managedScope']['complete'] === false
        && $store->get('pbx-puddle',$first['revisionId'])['contentHash'] === $first['contentHash'],
        'incomplete collection is retained separately and leaves the previous valid local revision intact');
    $recoveredRevision = $store->captureConfiguration($capture);
    puddleCheck($recoveredRevision['revisionId'] !== $failedRevision['revisionId']
        && $recoveredRevision['managedScope']['complete'] === true
        && $store->get('pbx-puddle',$first['revisionId'])['contentHash'] === $first['contentHash'],
        'successful capture after temporary failure creates a complete child without rewriting previous history');

    $changedGroups = $capture['collections'];
    $changedGroups['core.users']['objects'][0]['fields']['name'] = 'Test 1001 Changed';
    $changedCapture = ConfigurationCapture::create($capture['sourcePbxId'],$capture['modules'],$changedGroups,
        $capture['credentials'],$capture['blockingGaps']);
    $changedRevision = $store->captureConfiguration($changedCapture);
    $removedGroups = $oneGroup['collections'];
    $removedGroups['ringgroups']['objects'] = [];
    $removedCapture = ConfigurationCapture::create($oneGroup['sourcePbxId'],$oneGroup['modules'],$removedGroups,
        $oneGroup['credentials'],$oneGroup['blockingGaps']);
    $oneGroupRevision = $store->captureConfiguration($oneGroup);
    $removedRevision = $store->captureConfiguration($removedCapture);
    $removedDiff = $store->compare('pbx-puddle',$oneGroupRevision['revisionId'],$removedRevision['revisionId']);
    puddleCheck($changedRevision['revisionId'] !== $first['revisionId']
        && in_array('ringgroup:600',$removedDiff['changes']['remove'],true),
        'supported object modifications and removals create new local snapshots');
    try { $store->operationCatalogForRevision('pbx-puddle',$first['revisionId']); throw new RuntimeException('Capture was executable.'); }
    catch (RuntimeException $exception) {
        puddleCheck($exception->getMessage() === 'PUDDLE_CAPTURE_NOT_EXECUTABLE',
            'configuration capture cannot be mistaken for an executable reconstruction blueprint');
    }
    try { $store->markPublished('pbx-puddle',$first['revisionId'],[], 'provider-connection');
        throw new RuntimeException('Capture was published.'); }
    catch (RuntimeException $exception) {
        puddleCheck($exception->getMessage() === 'PUDDLE_CAPTURE_NOT_EXECUTABLE',
            'configuration capture cannot be marked provider-acknowledged');
    }
    try { $store->markApplied('pbx-puddle',$first['revisionId'],['complete'=>true,'objectIds'=>[]],['state'=>'configuration_verified']);
        throw new RuntimeException('Capture was marked applied.'); }
    catch (RuntimeException $exception) {
        puddleCheck($exception->getMessage() === 'PUDDLE_CAPTURE_NOT_EXECUTABLE',
            'local configuration capture cannot be marked applied or recovery-verified');
    }
    $listed = $store->list('pbx-puddle');
    puddleCheck(!isset($listed[0]['configurationCapture']) && !isset($listed[0]['blueprint']),
        'revision listings expose capture metadata, not the raw stored configuration payload');
    echo "Puddle discovery tests passed with synthetic PDO/FreePBX interface doubles; no real FreePBX host was used.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    if (is_dir($root)) { rmdir($root); }
}