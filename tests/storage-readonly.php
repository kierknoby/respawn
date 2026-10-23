<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function readonlyCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
function readonlyRemove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
    foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { readonlyRemove($path . '/' . $entry); } }
    rmdir($path);
}
function readonlyBlueprint(int $variation): array
{
    $source = SimulatedPbx::blueprint('pbx-alpha');
    $users = []; $devices = [];
    foreach (BuildSpec::plan($source) as $operation) {
        $number = $operation['object']['identity'];
        if ($operation['object']['type'] === 'user') { $users[$number] = $operation['parameters']; }
        elseif ($operation['object']['type'] === 'pjsip') {
            $device = $operation['parameters'];
            break;
        }
    }
    for ($index=0;$index<40;$index++) {
        $number=(string)(1001+$index);
        $users[$number]=['name'=>'Read-only synthetic '.$number,'voicemail'=>'novm'];
        $devices[$number]=array_replace($device,['user'=>$number,'description'=>'Read-only synthetic '.$number,
            'authUsername'=>'Read-only-'.$number,'secretRef'=>'secret:pbx-alpha:'.$number.':v1']);
    }
    return BuildSpec::create('pbx-alpha',$source['modules'],$users,$devices,[
        ['number'=>'600','description'=>'Read-only variation '.$variation,'strategy'=>'ringall','timeout'=>20,
            'members'=>['1001'],'destination'=>'hangup']]);
}
function readonlyWorker(string $mode, string $directory, string $coordination = ''): int
{
    $store = new JsonStateStore($directory);
    $revisions = new RevisionStore($store);
    if ($mode === 'hold') {
        $store->inspect(static function (array $state) use ($coordination): void {
            file_put_contents($coordination.'/reader-ready','ready');
            $deadline=microtime(true)+5;
            while (!file_exists($coordination.'/release-reader') && microtime(true)<$deadline) { usleep(10000); }
            if (!file_exists($coordination.'/release-reader')) { throw new RuntimeException('READONLY_TEST_RELEASE_TIMEOUT'); }
        });
        return 0;
    }
    if ($mode === 'prune-wait') {
        file_put_contents($coordination.'/writer-started','started');
        $revisions->capture(readonlyBlueprint(900));
        $revisions->setRetentionLimit(2);
        return 0;
    }
    if ($mode === 'read-loop') {
        for ($index=0; $index<100; $index++) {
            $heads = $revisions->heads('pbx-alpha');
            try { $revisions->get('pbx-alpha', $heads['lastCapturedRevisionId']); }
            catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(),'REVISION_NOT_FOUND')) { throw $exception; }
            }
            $revisions->list('pbx-alpha');
        }
        return 0;
    }
    if ($mode === 'write-loop') {
        for ($index=100; $index<140; $index++) {
            $revisions->capture(readonlyBlueprint($index));
            if ($index % 3 === 0) { $revisions->setRetentionLimit(5); }
        }
        return 0;
    }
    throw new RuntimeException('READONLY_WORKER_MODE_INVALID');
}

if (($argv[1] ?? '') === '--worker') {
    try { exit(readonlyWorker($argv[2],$argv[3],$argv[4] ?? '')); }
    catch (Throwable $exception) { fwrite(STDERR,$exception->getMessage()."\n"); exit(1); }
}

$root=sys_get_temp_dir().'/respawn-readonly-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $store=new JsonStateStore($root);
    $revisions=new RevisionStore($store,50);
    $ids=[];
    for ($index=0;$index<50;$index++) { $ids[]=$revisions->capture(readonlyBlueprint($index))['revisionId']; }
    $statePath=$root.'/state.json';
    $stateDigest=hash_file('sha256',$statePath);
    $chunkDigests=[];
    foreach (glob($root.'/chunks/*.json') as $chunk) { $chunkDigests[basename($chunk)]=hash_file('sha256',$chunk); }
    readonlyCheck($chunkDigests!==[],'fixture includes externally stored immutable chunks');
    for ($pass=0;$pass<5;$pass++) {
        foreach ($ids as $id) { $revisions->get('pbx-alpha',$id); }
        $revisions->list('pbx-alpha');
        $revisions->heads('pbx-alpha');
        $revisions->retentionLimit();
        $revisions->compare('pbx-alpha',$ids[0],$ids[49]);
        $revisions->operationCatalogForRevision('pbx-alpha',$ids[49]);
    }
    $after=[];
    foreach (glob($root.'/chunks/*.json') as $chunk) { $after[basename($chunk)]=hash_file('sha256',$chunk); }
    readonlyCheck(hash_file('sha256',$statePath)===$stateDigest && $after===$chunkDigests,
        'repeated get/list/heads/catalogue/comparison reads preserve state.json and every chunk byte-for-byte');

    $readers=[];
    for ($index=0;$index<4;$index++) {
        $readers[]=proc_open([PHP_BINARY,__FILE__,'--worker','read-loop',$root],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes);
        $readers[array_key_last($readers)]=[$readers[array_key_last($readers)],$pipes];
    }
    $writerPipes=[];
    $writer=proc_open([PHP_BINARY,__FILE__,'--worker','write-loop',$root],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$writerPipes);
    foreach ($readers as [$process,$pipes]) {
        $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
        readonlyCheck(is_resource($process) && proc_close($process)===0 && $error==='',
            'concurrent reader completes safely while capture and retention pruning write');
    }
    $error=stream_get_contents($writerPipes[2]); fclose($writerPipes[2]);
    readonlyCheck(is_resource($writer) && proc_close($writer)===0 && $error==='',
        'concurrent writer commits captures and prunes without corrupting readers');
    $revisions->get('pbx-alpha',$revisions->heads('pbx-alpha')['lastCapturedRevisionId']);

    $readLock=$root.'/state.lock';
    $barrier=$root.'/barrier'; mkdir($barrier,0700);
    $reader=proc_open([PHP_BINARY,__FILE__,'--worker','hold',$root,$barrier],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$readerPipes);
    readonlyCheck(is_resource($reader),'shared-lock reader process starts');
    $deadline=microtime(true)+5;
    while (!file_exists($barrier.'/reader-ready') && microtime(true)<$deadline) { usleep(10000); }
    readonlyCheck(file_exists($barrier.'/reader-ready'),'reader holds the shared lock while examining its snapshot');
    $lock=fopen($readLock,'rb');
    flock($lock,LOCK_EX|LOCK_NB) && throw new RuntimeException('FAIL: reader did not hold a shared state lock');
    flock($lock,LOCK_UN); fclose($lock);
    $beforeChunks=[];
    foreach (glob($root.'/chunks/*.json') as $chunk) { $beforeChunks[basename($chunk)]=hash_file('sha256',$chunk); }
    $pruner=proc_open([PHP_BINARY,__FILE__,'--worker','prune-wait',$root,$barrier],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$prunerPipes);
    $deadline=microtime(true)+5;
    while (!file_exists($barrier.'/writer-started') && microtime(true)<$deadline) { usleep(10000); }
    usleep(50000);
    $status=proc_get_status($pruner);
    readonlyCheck(is_resource($pruner) && $status['running']===true,
        'exclusive writer remains blocked while shared inspection lock is held');
    $during=[];
    foreach (glob($root.'/chunks/*.json') as $chunk) { $during[basename($chunk)]=hash_file('sha256',$chunk); }
    readonlyCheck($during===$beforeChunks,'chunk pruning cannot remove or rewrite data observed by an active reader');
    touch($barrier.'/release-reader');
    $readerError=stream_get_contents($readerPipes[2]); fclose($readerPipes[2]);
    readonlyCheck(proc_close($reader)===0 && $readerError==='', 'held reader completes after releasing its shared lock');
    $error=stream_get_contents($prunerPipes[2]); fclose($prunerPipes[2]);
    readonlyCheck(proc_close($pruner)===0 && $error==='', 'pruning proceeds safely after shared readers release their lock');
    $revisions->get('pbx-alpha',$revisions->heads('pbx-alpha')['lastCapturedRevisionId']);
    echo "Read-only revision storage regressions passed.\n";
} finally { readonlyRemove($root); }
