<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Storage\JsonStateStore;
use Respawn\Firewall\UnavailableFirewallSource;

function ownershipCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
function ownershipRemove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
    foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { ownershipRemove($path . '/' . $entry); } }
    rmdir($path);
}
function ownershipWorker(array $arguments): int
{
    $mode=$arguments[1] ?? '';
    $directory=$arguments[2] ?? '';
    if ($mode === '--service') {
        $uid=(int)$arguments[3]; $gid=(int)$arguments[4];
        if (posix_setgid($gid)!==true || posix_setuid($uid)!==true) { throw new RuntimeException('SERVICE_IDENTITY_SWITCH_FAILED'); }
        $store=new JsonStateStore($directory);
        $value=$store->inspect(static fn(array $state): string=>$state['ownershipProbe']['value'] ?? '');
        if ($value !== 'root-write') { throw new RuntimeException('SERVICE_READBACK_FAILED'); }
        $store->update(static function(array &$state): void { $state['ownershipProbe']['serviceWrite']=true; });
        return 0;
    }
    throw new RuntimeException('OWNERSHIP_WORKER_MODE_INVALID');
}
if (($argv[1] ?? '') === '--service') {
    try { exit(ownershipWorker($argv)); }
    catch (Throwable $exception) { fwrite(STDERR,$exception->getMessage()."\n"); exit(1); }
}

$root=sys_get_temp_dir().'/respawn-ownership-'.bin2hex(random_bytes(8));
$serviceParent=null;
mkdir($root,0700);
try {
    $directory=$root.'/ordinary';
    $store=new JsonStateStore($directory);
    $store->update(static function(array &$state): void { $state['ownershipProbe']['value']='ordinary-write'; });
    foreach ([$directory.'/state.json',$directory.'/state.lock'] as $path) {
        ownershipCheck(fileowner($path)===fileowner($directory) && filegroup($path)===filegroup($directory)
            && (fileperms($path)&0077)===0, basename($path).' keeps state-directory ownership and private mode');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid()!==0) {
        $original=file_get_contents($directory.'/state.json');
        chmod($directory.'/state.json',0000);
        $readFailed=false;
        try { $store->inspect(static fn(array $state): array=>$state); }
        catch (RuntimeException $exception) { $readFailed=str_contains($exception->getMessage(),'RESPAWN_STATE_FILE_UNREADABLE'); }
        chmod($directory.'/state.json',0600);
        ownershipCheck($readFailed && file_get_contents($directory.'/state.json')===$original,
            'inaccessible journal raises a stable diagnostic and is never reset or rewritten');
    } else { echo "SKIP: mode-000 read denial requires a non-root process.\n"; }
    $outside=$root.'/outside';mkdir($outside,0700);$link=$root.'/linked';symlink($outside,$link);
    $symlinkRejected=false;
    try { new JsonStateStore($link.'/child'); }
    catch (RuntimeException $exception) { $symlinkRejected=str_contains($exception->getMessage(),'SYMLINK'); }
    ownershipCheck($symlinkRejected && !is_dir($outside.'/child'),
        'state creation rejects symlinked ancestors without touching their targets');

    if (function_exists('posix_geteuid') && posix_geteuid()===0) {
        $account=posix_getpwnam('nobody');
        if (is_array($account)) {
            $serviceParent=sys_get_temp_dir().'/respawn-service-owned-'.bin2hex(random_bytes(8));mkdir($serviceParent,0700);
            chown($serviceParent,(int)$account['uid']);chgrp($serviceParent,(int)$account['gid']);
            $serviceDirectory=$serviceParent.'/respawn';
            $serviceStore=new JsonStateStore($serviceDirectory);
            $serviceStore->update(static function(array &$state): void {
                $state['ownershipProbe']['value']='root-write';
                $state['largeRecords']=array_fill(0,3000,str_repeat('synthetic',24));
            });
            $chunks=glob($serviceDirectory.'/chunks/*.json') ?: [];
            ownershipCheck($chunks!==[],'root write fixture produces immutable storage chunks');
            $managed=array_merge([$serviceDirectory.'/state.json',$serviceDirectory.'/state.lock'], $chunks);
            foreach ($managed as $path) {
                ownershipCheck(fileowner($path)===(int)$account['uid'] && filegroup($path)===(int)$account['gid']
                    && (fileperms($path)&0077)===0,basename($path).' remains owned by the service account after root CLI write');
            }
            $rootApp=new \Respawn\Application(['identity'=>'pbx-alpha','mode'=>'mock','intent'=>'rehearsal','activationPolicy'=>'none'],
                new JsonStateStore($serviceDirectory),new UnavailableFirewallSource());
            $rootApp->run('publish');
            $afterRootPublication=glob($serviceDirectory.'/chunks/*.json') ?: [];
            foreach (array_merge([$serviceDirectory.'/state.json',$serviceDirectory.'/state.lock'],$afterRootPublication) as $path) {
                ownershipCheck(fileowner($path)===(int)$account['uid'] && filegroup($path)===(int)$account['gid']
                    && (fileperms($path)&0077)===0,basename($path).' remains service-owned after a root CLI-equivalent publication');
            }
            $beforeStatus=hash_file('sha256',$serviceDirectory.'/state.json');
            $localStatus=new \Respawn\Application(['identity'=>'pbx-alpha','mode'=>'disabled','intent'=>'rehearsal','activationPolicy'=>'none'],
                new JsonStateStore($serviceDirectory),new UnavailableFirewallSource());
            $localStatus->run('status');
            ownershipCheck(hash_file('sha256',$serviceDirectory.'/state.json')===$beforeStatus,
                'root CLI-equivalent disabled status does not rewrite the journal');
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--service',$serviceDirectory,(string)$account['uid'],(string)$account['gid']],
                [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            ownershipCheck(is_resource($process) && proc_close($process)===0 && $stderr==='',
                'service account reads root-created journal/chunks and commits a GUI-equivalent update');
            $serviceStore->update(static function(array &$state): void { $state['ownershipProbe']['rootSecondWrite']=true; });
            $secondChunks=glob($serviceDirectory.'/chunks/*.json') ?: [];
            foreach (array_merge([$serviceDirectory.'/state.json',$serviceDirectory.'/state.lock'],$secondChunks) as $path) {
                ownershipCheck(fileowner($path)===(int)$account['uid'] && (fileperms($path)&0077)===0,
                    basename($path).' retains service ownership after subsequent root/service updates');
            }
            foreach (array_merge([$serviceDirectory.'/state.json',$serviceDirectory.'/state.lock'],$secondChunks) as $path) {
                chown($path,0);chgrp($path,0);
            }
            file_put_contents($serviceDirectory.'/unrelated.txt','preserve');chmod($serviceDirectory.'/unrelated.txt',0600);
            $beforeReject=[];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($serviceDirectory,FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile() && !$entry->isLink()) { $beforeReject[$entry->getPathname()]=[fileowner($entry->getPathname()),filegroup($entry->getPathname()),fileperms($entry->getPathname())&0777]; }
            }
            $rejected=false;
            try { JsonStateStore::repairOwnership($serviceDirectory,$serviceParent); }
            catch (RuntimeException $exception) { $rejected=str_contains($exception->getMessage(),'UNEXPECTED_PATH'); }
            $afterReject=[];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($serviceDirectory,FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isFile() && !$entry->isLink()) { $afterReject[$entry->getPathname()]=[fileowner($entry->getPathname()),filegroup($entry->getPathname()),fileperms($entry->getPathname())&0777]; }
            }
            ownershipCheck($rejected && $afterReject===$beforeReject,
                'repair preflight rejects unexpected files before changing ownership or creating files');
            unlink($serviceDirectory.'/unrelated.txt');
            $repair=JsonStateStore::repairOwnership($serviceDirectory,$serviceParent);
            ownershipCheck(($repair['state'] ?? '')==='storage_ownership_repaired'
                && fileowner($serviceDirectory.'/state.json')===(int)$account['uid'],
                'explicit repair restores only verified managed state paths without deleting data');
            $afterRepair=$serviceStore->inspect(static fn(array $state): array=>$state);
            ownershipCheck(!empty($afterRepair['ownershipProbe']['serviceWrite']) && !empty($afterRepair['ownershipProbe']['rootSecondWrite']),
                'ownership repair preserves existing journal data');
        } else { echo "SKIP: root/service mixed-UID regression requires a local service account.\n"; }
    } else {
        $rootRepairRejected=false;
        try { JsonStateStore::repairOwnership($directory,$root); }
        catch (RuntimeException $exception) { $rootRepairRejected=str_contains($exception->getMessage(),'REQUIRES_ROOT'); }
        ownershipCheck($rootRepairRejected,'ownership repair refuses non-root invocation');
        echo "SKIP: root/service mixed-UID regression requires root privileges and was not run here.\n";
    }
    echo "Storage ownership regressions passed. Live FreePBX account integration remains a separate host test.\n";
} finally {
    if (is_string($serviceParent) && is_dir($serviceParent)) { ownershipRemove($serviceParent); }
    ownershipRemove($root);
}
