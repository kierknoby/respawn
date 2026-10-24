<?php
declare(strict_types=1);

interface BMO { public function doConfigPageInit($page); }
class FreePBX_Helpers
{
    private array $configuration=[];
    public int $setCalls=0;
    public function getConfig(string $key): mixed { return $this->configuration[$key] ?? null; }
    public function setConfig(string $key, mixed $value): void { $this->configuration[$key]=$value; $this->setCalls++; }
}
final class GuiTestUser
{
    public function __construct(public bool $allowed=true) {}
    public function checkSection(string $section): bool { return $section==='respawn' && $this->allowed; }
}
final class GuiTestConfig { public function get(string $key): string { return $GLOBALS['guiStateRoot']; } }
final class GuiTestCore { public function listUsers(): array { return []; } }
final class GuiTestRinggroups { public function get(string $number): array { return []; } }
final class GuiTestModules
{
    public function checkStatus(string $name): bool { return true; }
    public function getInfo(string $name): array { return [$name=>['version'=>'17.0.1']]; }
}
final class GuiTestFreePbx
{
    public object $Config; public object $Firewall; public object $Core; public object $Ringgroups; public object $astman; public object $Modules;
    public PDO $Database;
    public function __construct()
    {
        $this->Config=new GuiTestConfig();$this->Firewall=new stdClass();$this->Core=new GuiTestCore();
        $this->Ringgroups=new GuiTestRinggroups();$this->astman=new stdClass();$this->Modules=new GuiTestModules();
        $this->Database=new PDO('sqlite::memory:');
        $this->Database->exec('CREATE TABLE devices (id TEXT, tech TEXT); CREATE TABLE ringgroups (grpnum TEXT);');
    }
}
final class FreePBX { public static object $respawn; public static function Respawn(): object { return self::$respawn; } }

if (!defined('FREEPBX_IS_AUTH')) { define('FREEPBX_IS_AUTH',true); }
require __DIR__ . '/../module/bootstrap.php';
require __DIR__ . '/../module/Respawn.class.php';

function guiCheck(bool $condition,string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    echo 'PASS: '.$message."\n";
}
function guiRemove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { if (file_exists($path)||is_link($path)) { unlink($path); } return; }
    foreach (scandir($path) as $entry) { if ($entry!=='.' && $entry!=='..') { guiRemove($path.'/'.$entry); } }
    rmdir($path);
}

if (in_array($argv[1] ?? '',['--deny','--bad-csrf','--oversize'],true)) {
    $GLOBALS['guiStateRoot']=sys_get_temp_dir().'/respawn-gui-child-'.bin2hex(random_bytes(4));
    mkdir($GLOBALS['guiStateRoot'],0700);
    session_start();
    $_SESSION=['AMP_user'=>new GuiTestUser(($argv[1] ?? '')!=='--deny'),'respawn_csrf'=>'known-test-csrf'];
    register_shutdown_function(static function (): void { guiRemove($GLOBALS['guiStateRoot']); });
    $_SERVER=['REQUEST_METHOD'=>'POST','CONTENT_LENGTH'=>($argv[1] ?? '')==='--oversize'?'16385':'100'];
    $_POST=['csrf'=>($argv[1] ?? '')==='--bad-csrf'?'wrong':'known-test-csrf','action'=>'refresh-status'];
    register_shutdown_function(static function (): void { fwrite(STDOUT,'HTTP_STATUS='.http_response_code()."\n"); });
    (new \FreePBX\modules\Respawn(new GuiTestFreePbx()))->doConfigPageInit('respawn');
    exit(0);
}

$root=sys_get_temp_dir().'/respawn-gui-'.bin2hex(random_bytes(8));
mkdir($root,0700);$GLOBALS['guiStateRoot']=$root;
try {
    session_start();
    $_SESSION=['AMP_user'=>new GuiTestUser()];
    $_SERVER=['REQUEST_METHOD'=>'GET','CONTENT_LENGTH'=>'0'];$_POST=[];$_GET=[];
    $module=new \FreePBX\modules\Respawn(new GuiTestFreePbx());
    $module->install();$module->setCalls=0;
    $module->doConfigPageInit('respawn');
    $initialCapture=$module->captureLocalRevision();
    $sameCapture=$module->captureLocalRevision();
    guiCheck(($initialCapture['state'] ?? '')==='configuration_captured'
        && ($initialCapture['configurationComplete'] ?? false)===true
        && ($initialCapture['credentialStatus'] ?? '')==='not_required'
        && ($sameCapture['state'] ?? '')==='configuration_unchanged'
        && ($initialCapture['revisionId'] ?? '')===($sameCapture['revisionId'] ?? ''),
        'manual local capture works with provider disabled, no configured Ring Group scope, no objects, and no credential manifest');
    guiCheck(method_exists($module,'doConfigPageInit') && (new ReflectionMethod($module,'doConfigPageInit'))->getNumberOfRequiredParameters()===1,
        'BMO page-init hook implements the required one-argument lifecycle');
    guiCheck(isset($_SESSION['respawn_csrf']) && isset($_SESSION['respawn_launch_key']),'page lifecycle initializes existing session CSRF and launch tokens');
    $settings=$module->settings();
    $_SERVER['REQUEST_METHOD']='POST';$_SERVER['CONTENT_LENGTH']='512';
    $_POST=['csrf'=>$_SESSION['respawn_csrf'],'view'=>'settings','action'=>'settings','mode'=>'disabled',
        'intent'=>'rehearsal','activationPolicy'=>'none','groups'=>'600,601','secretManifest'=>'',
        'protectionIntervalSeconds'=>'300','endpoint'=>'','credentialRef'=>'','allowedHost'=>''];
    $module->doConfigPageInit('respawn');
    $feedback=$module->consumePageFeedback();
    guiCheck($module->setCalls===1 && ($feedback['result']['state'] ?? '')==='local settings saved; no external action requested',
        'POST settings are dispatched exactly once through BMO and the page consumes one feedback result');
    guiCheck(($feedback['view'] ?? '')==='settings' && $module->consumePageFeedback()===null,
        'postback preserves selected view and flash feedback is consumed once');
    guiCheck(\FreePBX\modules\Respawn::pageRedirectLocation('settings')==='?display=respawn&view=settings'
        && \FreePBX\modules\Respawn::pageRedirectLocation('https://outside.invalid')==='?display=respawn&view=overview',
        'post/redirect/get returns locally to the submitted view and rejects external redirect input');
    $_POST=['csrf'=>$_SESSION['respawn_csrf'],'view'=>'recovery','action'=>'respawn','sessionLaunchKey'=>$_SESSION['respawn_launch_key']];
    $_SERVER['CONTENT_LENGTH']='256';$module->doConfigPageInit('respawn');$disabledLaunch=$module->consumePageFeedback();
    guiCheck(($disabledLaunch['result']['state'] ?? '')==='failed' && ($disabledLaunch['result']['error']['code'] ?? '')==='PROVIDER_DISABLED'
        && str_contains($disabledLaunch['result']['error']['message'] ?? '', 'no recovery operation was submitted'),
        'disabled provider mode cannot trigger a recovery launch and reports an explicit non-submission');
    $module->setCalls=0;$_POST=['csrf'=>$_SESSION['respawn_csrf'],'action'=>'settings','mode'=>'provider'];
    $module->doConfigPageInit('other');
    guiCheck($module->setCalls===0,'BMO page-init ignores unrelated FreePBX pages');
    foreach ([['--deny','HTTP_STATUS=403'],['--bad-csrf','HTTP_STATUS=403'],['--oversize','HTTP_STATUS=413']] as [$mode,$expected]) {
        $command=[PHP_BINARY,__FILE__,$mode];$pipes=[];
        $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        guiCheck(is_resource($process) && proc_close($process)===0 && str_contains($stdout,$expected)
            && ($mode!=='--bad-csrf' || str_contains($stdout,'Request verification failed.')) && $stderr==='',
            $mode.' remains blocked by the lifecycle authorization/CSRF/size gate');
    }
    $stateStore=new \Respawn\Storage\JsonStateStore($root.'/respawn');
    $stateStore->update(static function (array &$state): void {
        $state['protection']['pbx-test']=['enabled'=>false,'outbox'=>[]];
        $state['submissions']['sub-test']=['pbxId'=>'pbx-test','state'=>'authoritative_not_found'];
        $state['localPublications']['disabled-test']=['ack'=>null];
    });
    $statePath=$root.'/respawn/state.json';$before=hash_file('sha256',$statePath);
    for ($index=0;$index<4;$index++) {
        $module->protectionStatus();
        $module->assertRecoveryConflictsClear('pbx-test');
        $module->publicationDiffersFromRevision('pbx-test',str_repeat('a',64));
        $application=new \Respawn\Application(['identity'=>'pbx-test','mode'=>'disabled','intent'=>'rehearsal','activationPolicy'=>'none'],
            new \Respawn\Storage\JsonStateStore($root.'/respawn'),new \Respawn\Firewall\UnavailableFirewallSource());
        $application->cachedStatus();
        $application->run('status');
    }
    guiCheck(hash_file('sha256',$statePath)===$before,
        'repeated protection, conflict, publication and provider-status inspection preserve persistent state byte-for-byte');
    $page=file_get_contents(__DIR__.'/../module/page.respawn.php');
    guiCheck(str_contains($page,'Supported configuration complete:')
        && str_contains($page,'Independent recovery readiness:') && str_contains($page,'blockingGaps'),
        'capture results can show collection completeness, credential status and sanitised blocking gaps separately');
    guiCheck(str_contains($page,'Last retained revision') && str_contains($page,'Storage health')
        && str_contains($page,'Recovery information') && str_contains($page,'Unavailable'),
        'Pond diagnostics expose authenticated retention and recovery state without inventing unavailable values');
    foreach (['overview','protection','revisions','recovery','settings'] as $view) {
        guiCheck(\FreePBX\modules\Respawn::pageView($view)===$view && str_contains($page,"'".$view."'"),$view.' view is server-navigable');
    }
    foreach (['revision-capture','revision-plan','revision-execute','revision-compare','capabilities','export','publish','verify',
        'respawn','reconcileSubmission','retrySubmission','cancel','teardown','activate','refresh-status','protection-reconcile'] as $action) {
        $moduleSource=file_get_contents(__DIR__.'/../module/Respawn.class.php');
        $controllerSource=file_get_contents(__DIR__.'/../module/src/OperatorController.php');
        guiCheck(str_contains($page,'value="'.$action.'"') || str_contains($page,"'".$action."'")
            || str_contains($moduleSource,"'".$action."'") || str_contains($controllerSource,"'".$action."'"),
            $action.' remains accessible through the reorganized interface');
    }
    guiCheck(!str_contains($page,"REQUEST_METHOD'] === 'POST") && str_contains($page,'name="csrf"')
        && str_contains($page,'respawn-diagnostics') && str_contains($page,'<details'),
        'page is render-only after BMO dispatch and retains CSRF fields plus expandable diagnostics');
    FreePBX::$respawn=$module;
    foreach (['overview'=>'Protection overview','protection'=>'Protection','revisions'=>'Revisions','recovery'=>'Recovery','settings'=>'Settings and policy'] as $view=>$heading) {
        $_GET=['view'=>$view];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
        ob_start();require __DIR__.'/../module/page.respawn.php';$html=ob_get_clean();
        guiCheck(str_contains($html,'id="respawn-title"') && str_contains($html,$heading)
            && str_contains($html,'name="csrf"') && str_contains($html,'</html>')===false,
            $view.' view renders server-side headings and CSRF-protected actions without requiring a frontend runtime');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid()!==0) {
        $brokenRoot=$root.'/storage-failure';mkdir($brokenRoot,0700);
        $brokenStore=new \Respawn\Storage\JsonStateStore($brokenRoot.'/respawn');
        $brokenStore->update(static function(array &$state): void { $state['preservationSentinel']='keep'; });
        $journal=$brokenRoot.'/respawn/state.json';$journalDigest=hash_file('sha256',$journal);chmod($journal,0700);chmod($journal,0000);
        $GLOBALS['guiStateRoot']=$brokenRoot;FreePBX::$respawn=new \FreePBX\modules\Respawn(new GuiTestFreePbx());
        ob_start();require __DIR__.'/../module/page.respawn.php';$failureHtml=ob_get_clean();
        chmod($journal,0600);
        guiCheck(str_contains($failureHtml,'RESPAWN_STORAGE_UNAVAILABLE')
            && str_contains($failureHtml,'Respawn storage is unavailable') && !str_contains($failureHtml,$brokenRoot)
            && hash_file('sha256',$journal)===$journalDigest,
            'unreadable state shows a stable sanitised alert and preserves journal bytes');
        FreePBX::$respawn=$module;$GLOBALS['guiStateRoot']=$root;
    } else { echo "SKIP: permission-denied GUI render requires a non-root process.\n"; }
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $brokenRoot=$root.'/storage-failure';
        $brokenStore=new \Respawn\Storage\JsonStateStore($brokenRoot.'/respawn');
        $brokenStore->update(static function(array &$state): void { $state['sentinel']='preserve'; });
        chmod($brokenRoot.'/respawn/state.json',0000);
        $GLOBALS['guiStateRoot']=$brokenRoot;
        FreePBX::$respawn=new \FreePBX\modules\Respawn(new GuiTestFreePbx());
        ob_start();require __DIR__.'/../module/page.respawn.php';$failureHtml=ob_get_clean();
        chmod($brokenRoot.'/respawn/state.json',0600);
        guiCheck(str_contains($failureHtml,'Respawn storage is unavailable')
            && str_contains($failureHtml,'RESPAWN_STORAGE_UNAVAILABLE')
            && !str_contains($failureHtml,$brokenRoot),
            'inaccessible state renders a concise sanitized GUI diagnostic without exposing its path or overwriting data');
        FreePBX::$respawn=$module;$GLOBALS['guiStateRoot']=$root;
    } else { echo "SKIP: inaccessible-file GUI diagnostic requires a non-root test account.\n"; }
    echo "FreePBX BMO/GUI regressions passed; framework and native browser integration still require the installed FreePBX host.\n";
} finally { guiRemove($root); }
