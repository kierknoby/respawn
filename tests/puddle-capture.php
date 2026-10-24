<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\ConfigurationCapture;
use Respawn\Reconstruction\FreePbxConfigurationCollector;
use Respawn\Reconstruction\NativeProfile;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;
use Respawn\Diagnostic;

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
    public function __construct(private array $overrides = []) {}
    public function connected(): bool { return true; }
    public function database_get(string $family, string $key): string
    {
        if (array_key_exists($family . '/' . $key, $this->overrides)) {
            return $this->overrides[$family . '/' . $key];
        }
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
    puddleCheck($capture['blockingGaps'] === [] && $capture['credentials']['state'] === 'not_configured',
        'missing secret manifest is credential status, not incomplete non-secret configuration');
    $previousCapture = ConfigurationCapture::create($capture['sourcePbxId'], $capture['modules'],
        $capture['collections'], $capture['credentials'], ['CREDENTIALS:SECRET_MANIFEST_MISSING']);
    $previousBytes = ConfigurationCapture::canonicalBytes($previousCapture);
    ConfigurationCapture::verifyHash($previousCapture);
    puddleCheck(ConfigurationCapture::canonicalBytes($previousCapture) === $previousBytes,
        'previously retained snapshots with credential gaps keep their original canonical bytes and hash');
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
    $captureConnection = 'https-v1:test-connection';
    $captureKey = hash('sha256',$captureConnection . "\0pbx-puddle\0" . $first['revisionId'] . "\0" . $capture['contentHash']);
    $captureAck = ['pbxId'=>'pbx-puddle','connectionId'=>$captureConnection,'revisionId'=>$first['revisionId'],
        'captureId'=>$first['revisionId'],'contentHash'=>$capture['contentHash'],'idempotencyKey'=>$captureKey,
        'retentionReceipt'=>'synthetic-retention-receipt','publicationId'=>'synthetic-publication','version'=>1,
        'recoveryReady'=>false];
    $store->markConfigurationCaptureRetained('pbx-puddle',$first['revisionId'],$captureConnection,$captureAck);
    $store->markConfigurationCaptureVerified('pbx-puddle',$first['revisionId'],$captureConnection,$captureAck,$capture);
    $retainedLocal = $store->get('pbx-puddle',$first['revisionId']);
    puddleCheck(($retainedLocal['retentionState'] ?? '') === 'independently_readback_verified'
        && ($retainedLocal['recoveryReady'] ?? true) === false
        && ($store->heads('pbx-puddle')['lastVerifiedRevisionId'] ?? null) === null,
        'capture retention/read-back is recorded without marking executable or recovery-verified revision heads');
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
    [$guiPbx,$guiSecret] = puddleFixture($root, ['7290'=>['7201','7202']], ['7201','7202']);
    $guiPbx->Database->exec("ALTER TABLE users ADD COLUMN mohclass TEXT;
        UPDATE users SET mohclass = 'synthetic-music-class';
        UPDATE devices SET hint_override = 'synthetic-hint';
        UPDATE sip SET data = 'yes' WHERE keyword = 'aggregate_mwi';
        DELETE FROM sip WHERE keyword IN ('force_callerid','mailbox','vmexten','webrtc','username');");
    $guiPbx->astman = new PuddleTestManager(['AMPUSER/7201/concurrency_limit'=>'3',
        'AMPUSER/7202/concurrency_limit'=>'3']);
    $guiPbx->Ringgroups->rows['7290']['elsewhere'] = 'synthetic-elsewhere';
    foreach (['7201','7202'] as $number) {
        $guiPbx->Database->prepare('INSERT INTO sip VALUES (?,?,?)')->execute(
            [$number,'secret_origional','synthetic-original-credential']);
    }
    $guiCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    $guiRevision = $store->captureConfiguration($guiCapture);
    puddleCheck($guiRevision['managedScope']['complete'] === true
        && $guiCapture['blockingGaps'] === [] && $guiCapture['credentials']['state'] === 'not_configured'
        && $guiCapture['credentials']['recoveryReady'] === false,
        'observed FreePBX 17 GUI field differences capture all five objects without a credential manifest; concealed values are synthetic');
    $guiUsers = $guiCapture['collections']['core.users']['objects'];
    $guiDevices = $guiCapture['collections']['core.pjsip']['objects'];
    $guiGroup = $guiCapture['collections']['ringgroups']['objects'][0];
    $expectedGroup = $guiPbx->Ringgroups->rows['7290'];
    ksort($expectedGroup,SORT_STRING);
    puddleCheck(array_column($guiUsers,'id') === ['7201','7202']
        && array_column($guiDevices,'id') === ['7201','7202'] && $guiGroup['id'] === '7290'
        && $guiGroup['fields'] === $expectedGroup
        && $guiGroup['fields']['grplist'] === '7201-7202',
        'all five identities and exact Ring Group settings/member relationships are retained without destination rewriting');
    foreach ($guiUsers as $guiUser) {
        $userQuery = $guiPbx->Database->prepare('SELECT * FROM users WHERE extension = ?');
        $userQuery->execute([$guiUser['id']]);
        $expectedUserRow = $userQuery->fetch(PDO::FETCH_ASSOC);
        unset($expectedUserRow['password']);
        ksort($expectedUserRow,SORT_STRING);
        puddleCheck($guiUser['fields']['row'] === $expectedUserRow
            && $guiUser['fields']['row']['mohclass'] === 'synthetic-music-class'
            && $guiUser['fields']['ast']['concurrency_limit'] === '3',
            'user ' . $guiUser['id'] . ' retains actual non-secret SQL and AMPUSER settings, including mohclass and concurrency_limit');
    }
    foreach ($guiDevices as $guiDevice) {
        $deviceFields = $guiDevice['fields'];
        $settingsQuery = $guiPbx->Database->prepare('SELECT keyword, data FROM sip WHERE id = ?');
        $settingsQuery->execute([$guiDevice['id']]);
        $expectedSettings = $settingsQuery->fetchAll(PDO::FETCH_KEY_PAIR);
        unset($expectedSettings['secret'],$expectedSettings['md5_cred'],$expectedSettings['secret_origional']);
        ksort($expectedSettings,SORT_STRING);
        puddleCheck($deviceFields['settings'] === $expectedSettings
            && $deviceFields['row']['hint_override'] === 'synthetic-hint'
            && $deviceFields['row']['user'] === $guiDevice['id']
            && $deviceFields['ast']['user'] === $guiDevice['id']
            && $deviceFields['settings']['aggregate_mwi'] === 'yes'
            && $deviceFields['settings']['max_contacts'] === '1'
            && !array_intersect(['force_callerid','mailbox','vmexten','webrtc','username'], array_keys($deviceFields['settings']))
            && !array_key_exists('authUsername',$deviceFields),
            'device ' . $guiDevice['id'] . ' retains exact settings and relationships, without invented optional values or username');
    }
    $guiPbx->Database->prepare('UPDATE sip SET data = ? WHERE keyword = ?')->execute(
        ['synthetic-changed-original-credential','secret_origional']);
    $originalCredentialChange = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    $originalCredentialRevision = $store->captureConfiguration($originalCredentialChange);
    $captureAndRevisionBytes = json_encode([$guiCapture,$originalCredentialChange,
        $store->get('pbx-puddle',$originalCredentialRevision['revisionId'])], JSON_THROW_ON_ERROR);
    puddleCheck(ConfigurationCapture::canonicalBytes($originalCredentialChange) === ConfigurationCapture::canonicalBytes($guiCapture)
        && $originalCredentialChange['contentHash'] === $guiCapture['contentHash']
        && $originalCredentialRevision['revisionId'] === $guiRevision['revisionId']
        && !str_contains($captureAndRevisionBytes,'secret_origional')
        && !str_contains($captureAndRevisionBytes,'synthetic-original-credential')
        && !str_contains($captureAndRevisionBytes,'synthetic-changed-original-credential'),
        'secret_origional is excluded from capture/publication source and stored revision; changing it alone preserves canonical bytes, hash and revision');
    $guiPbx->Database->exec("UPDATE users SET password = 'synthetic-user-password';
        UPDATE sip SET data = 'synthetic-md5-material' WHERE keyword = 'md5_cred';
        UPDATE sip SET data = 'synthetic-rotated-sip-secret' WHERE keyword = 'secret';");
    $guiPbx->astman = new PuddleTestManager(['AMPUSER/7201/concurrency_limit'=>'3',
        'AMPUSER/7202/concurrency_limit'=>'3','AMPUSER/7201/password'=>'synthetic-ampuser-password']);
    $secretOnlyChange = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    puddleCheck($secretOnlyChange['contentHash'] === $guiCapture['contentHash']
        && !str_contains(json_encode($secretOnlyChange),$guiSecret)
        && !str_contains(json_encode($secretOnlyChange),'synthetic-user-password')
        && !str_contains(json_encode($secretOnlyChange),'synthetic-md5-material')
        && !str_contains(json_encode($secretOnlyChange),'synthetic-rotated-sip-secret')
        && !str_contains(json_encode($secretOnlyChange),'synthetic-ampuser-password'),
        'SQL/AMPUSER passwords and SIP secret/md5 material are excluded and secret-only changes do not affect non-secret hashes');
    $guiRestartStore = new RevisionStore(new JsonStateStore($root . '/revisions'));
    puddleCheck($guiRestartStore->captureConfiguration($secretOnlyChange)['revisionId'] === $guiRevision['revisionId']
        && $guiRestartStore->get('pbx-puddle',$guiRevision['revisionId'])['configurationCapture'] === $guiCapture,
        'populated source-shaped capture survives restart and deduplicates without changing its canonical representation');
    $previousGuiRevision = $guiRevision;
    foreach ([
        ['core.users','7201',static fn () => $guiPbx->Database->exec("UPDATE users SET mohclass = 'synthetic-other-class' WHERE extension = '7201'")],
        ['core.pjsip','7201',static fn () => $guiPbx->Database->exec("UPDATE devices SET hint_override = '' WHERE id = '7201'")],
        ['core.pjsip','7201',static fn () => $guiPbx->Database->exec("UPDATE sip SET data = 'no' WHERE id = '7201' AND keyword = 'aggregate_mwi'")],
        ['ringgroups','7290',static function () use ($guiPbx): void { $guiPbx->Ringgroups->rows['7290']['elsewhere'] = ''; }],
    ] as [$collectionName,$objectNumber,$mutate]) {
        $mutate();
        $changedGuiCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
        $changedGuiRevision = $guiRestartStore->captureConfiguration($changedGuiCapture);
        $guiDiff = $guiRestartStore->compare('pbx-puddle',$previousGuiRevision['revisionId'],$changedGuiRevision['revisionId']);
        puddleCheck($changedGuiCapture['contentHash'] !== $previousGuiRevision['contentHash']
            && $changedGuiRevision['parentRevisionId'] === $previousGuiRevision['revisionId']
            && count($guiDiff['changes']['update']) === 1,
            $collectionName . ':' . $objectNumber . ' non-default setting changes are hashed, versioned and compared');
        $previousGuiRevision = $changedGuiRevision;
    }
    $nativeUserDenied = false;
    try {
        NativeProfile::userParameters((new \Respawn\Reconstruction\FreePbxSnapshot($guiPbx->Database,$guiPbx->astman))->read('user','7201'),'7201');
    } catch (RuntimeException) { $nativeUserDenied = true; }
    $guiPbx->Ringgroups->rows['7290']['elsewhere'] = 'synthetic-elsewhere';
    $nativeGroupDenied = false;
    try { (new \Respawn\Reconstruction\FreePbxRinggroups($guiPbx->Ringgroups,'pbx-puddle',static fn (): bool => false))->read('7290'); }
    catch (RuntimeException) { $nativeGroupDenied = true; }
    $captureExecutionDenied = false;
    try { $guiRestartStore->operationCatalogForRevision('pbx-puddle',$guiRevision['revisionId']); }
    catch (RuntimeException $exception) { $captureExecutionDenied = $exception->getMessage() === 'PUDDLE_CAPTURE_NOT_EXECUTABLE'; }
    puddleCheck($nativeUserDenied && $nativeGroupDenied && $captureExecutionDenied,
        'capture-compatible GUI differences do not broaden native reconstruction or make populated captures executable');
    $guiPbx->Database->prepare('INSERT INTO sip VALUES (?,?,?)')->execute(['7201','auth_token','synthetic-do-not-retain-token']);
    $unknownCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    puddleCheck($unknownCapture['collections']['core.pjsip']['objects'][0]['state'] === 'unsupported'
        && !str_contains(json_encode($unknownCapture),'synthetic-do-not-retain-token'),
        'unreviewed PJSIP fields remain unsupported and their values never enter captures');
    $guiPbx->Database->exec("DELETE FROM sip WHERE keyword = 'auth_token';
        UPDATE sip SET data = 'sip:synthetic-user:synthetic-uri-password@example.invalid' WHERE keyword = 'outbound_proxy';");
    $uriCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    puddleCheck($uriCapture['collections']['core.pjsip']['objects'][0]['state'] === 'unsupported'
        && !str_contains(json_encode($uriCapture),'synthetic-uri-password'),
        'credential-bearing URI values are rejected rather than retained as ordinary text settings');
    $guiPbx->Database->exec("UPDATE sip SET data = '' WHERE keyword = 'outbound_proxy';
        UPDATE devices SET user = '9999' WHERE id = '7201';");
    $relationshipCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    puddleCheck($relationshipCapture['collections']['core.pjsip']['objects'][0]['state'] === 'unsupported',
        'inconsistent device relationships remain unsupported rather than being replaced with assumed bindings');
    $guiPbx->Ringgroups->rows['7290']['elsewhere'] = ['unrepresentable'];
    $invalidGroupCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    puddleCheck($invalidGroupCapture['collections']['ringgroups']['objects'][0]['state'] === 'unsupported',
        'non-scalar Ring Group configuration remains explicitly unrepresentable');
    $guiPbx->Database->exec('ALTER TABLE users ADD COLUMN unreviewed_setting TEXT');
    $unknownUserCapture = (new FreePbxConfigurationCollector($guiPbx,'pbx-puddle'))->capture();
    puddleCheck($unknownUserCapture['collections']['core.users']['objects'][0]['state'] === 'unsupported',
        'unknown user columns are not silently discarded even when null');
    $v2Modules = [];
    foreach ($capture['modules'] as $name=>$module) { if (!empty($module['available']) && is_string($module['version'])) { $v2Modules[$name] = $module['version']; } }
    $legacyBlueprint = \Respawn\Reconstruction\BuildSpec::create('pbx-puddle',$v2Modules,
        ['1001'=>['name'=>'Test 1001','voicemail'=>'novm']],
        ['1001'=>['user'=>'1001','description'=>'Test 1001','authUsername'=>'Test-1001',
            'secretRef'=>'secret:pbx-puddle:1001:v1','settings'=>[
                'context'=>'from-internal','transport'=>'','dtmfmode'=>'rfc4733','allow'=>'ulaw,alaw','disallow'=>'all',
                'direct_media'=>'no','rtp_symmetric'=>'yes','force_rport'=>'yes','rewrite_contact'=>'yes',
                'max_contacts'=>1,'remove_existing'=>'yes','media_encryption'=>'no']]],[]);
    $legacyRevision = $store->capture($legacyBlueprint);
    $mixedDiff = $store->compare('pbx-puddle',$first['revisionId'],$legacyRevision['revisionId']);
    puddleCheck($mixedDiff['changes'] === ['create'=>[],'update'=>['pjsip:1001','user:1001'],'remove'=>[],'unchanged'=>[]]
        && $mixedDiff['captureMetadataChanged'] === true,
        'richer capture/v2 comparison preserves identities and reports source-field differences without rewriting old revisions');

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
    $changedGroups['core.users']['objects'][0]['fields']['row']['name'] = 'Test 1001 Changed';
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
    $retentionDiagnostic = Diagnostic::forThrowable(new RuntimeException('PUDDLE_READBACK_MISMATCH'),'revision-verify');
    puddleCheck(($retentionDiagnostic['code'] ?? '') === 'PUDDLE_READBACK_MISMATCH'
        && !str_contains(json_encode($retentionDiagnostic),'synthetic-original-secret'),
        'capture-retention failures use a stable sanitised operator diagnostic');
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