<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\DesiredStatePlanner;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function capacityCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
function capacityBlueprint(int $revision, int $count = 40, int $groupCount = 1): array
{
    $template = SimulatedPbx::blueprint('pbx-alpha');
    $users = []; $devices = [];
    foreach (BuildSpec::plan($template) as $operation) {
        if ($operation['object']['type'] === 'pjsip') { $device = $operation['parameters']; break; }
    }
    for ($index = 0; $index < $count; $index++) {
        $number = (string) (1001 + $index);
        $users[$number] = ['name'=>'Synthetic ' . $number, 'voicemail'=>'novm'];
        $devices[$number] = array_replace($device, ['user'=>$number, 'description'=>'Synthetic ' . $number,
            'authUsername'=>'Synthetic-' . $number, 'secretRef'=>'secret:pbx-alpha:' . $number . ':v1']);
    }
    $groups = [];
    for ($index = 0; $index < $groupCount; $index++) {
        $groups[] = ['number'=>(string) (600 + $index), 'description'=>'Revision ' . $revision,
            'strategy'=>'ringall', 'timeout'=>20, 'members'=>['1001'], 'destination'=>'hangup'];
    }
    return BuildSpec::create('pbx-alpha', $template['modules'], $users, $devices, $groups);
}
function capacityRemove(string $path): void
{
    if (!is_dir($path) || is_link($path)) { if (file_exists($path)) { unlink($path); } return; }
    foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { capacityRemove($path . '/' . $entry); } }
    rmdir($path);
}
if (($argv[1] ?? '') === '--worker') {
    $worker = new RevisionStore(new JsonStateStore($argv[2]));
    if (($argv[3] ?? '') === 'interrupt') {
        (new JsonStateStore($argv[2]))->update(static function (array &$state): void {
            $state['interrupted'] = true;
            posix_kill(getmypid(), SIGKILL);
        });
    } elseif (($argv[3] ?? '') === 'exhaust') {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, 512, 512);
        $worker->capture(capacityBlueprint(10001));
    } else { $worker->capture(capacityBlueprint((int) $argv[3])); }
    exit;
}
$root = sys_get_temp_dir() . '/respawn-capacity-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $store = new RevisionStore(new JsonStateStore($root));
    $ids = [];
    for ($index = 0; $index < 50; $index++) {
        $ids[] = $store->capture(capacityBlueprint($index))['revisionId'];
        if ($index === 4) { $legacy = (new JsonStateStore($root))->update(static fn (array &$state): array => $state); }
    }
    capacityCheck(count($store->list('pbx-alpha')) === 50, '40 users, 40 PJSIP devices and one group retain all 50 changed revisions');
    foreach ($ids as $index => $id) {
        capacityCheck($store->get('pbx-alpha', $id)['contentHash'] === capacityBlueprint($index)['contentHash'], 'retained revision ' . $index . ' retrieves its exact hash');
    }
    capacityCheck(filesize($root . '/state.json') < 1048576 && count(glob($root . '/chunks/*.json')) > 0,
        'large immutable records are outside the bounded atomic journal');
    $store = new RevisionStore(new JsonStateStore($root));
    capacityCheck($store->get('pbx-alpha', $ids[49])['revisionId'] === $ids[49], 'restart preserves the latest valid revision');
    $comparison = $store->compare('pbx-alpha', $ids[0], $ids[49]);
    capacityCheck(count($comparison['changes']['update']) === 1, 'comparison across chunked historical revisions detects only the changed group');
    foreach (['revert','regenerate'] as $action) {
        $selected = $store->get('pbx-alpha', $ids[0]);
        $plan = DesiredStatePlanner::plan($selected['blueprint'], [], $selected['managedScope'], 'synthetic-target',
            $ids[0], $action, ['create'=>true,'update'=>true,'remove'=>true,'apply'=>true,'verify'=>true,'export'=>true], 'test-owner');
        $store->savePlan('pbx-alpha', $plan);
        capacityCheck($store->getPlan('pbx-alpha', $plan['planId']) === $plan, $action . ' plan survives durable storage alongside 50 large revisions');
    }
    $latest = $store->get('pbx-alpha', $ids[49]);
    $worker = static function (string $mode) use ($root): int {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root, $mode], [0=>['file','/dev/null','r'],
            1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('CAPACITY_WORKER_FAILED'); }
        return proc_close($process);
    };
    $before = hash_file('sha256', $root . '/state.json');
    capacityCheck($worker('interrupt') !== 0 && hash_file('sha256', $root . '/state.json') === $before,
        'killed writer leaves the previous journal and all reachable immutable revisions intact');
    capacityCheck($worker('exhaust') !== 0 && hash_file('sha256', $root . '/state.json') === $before
        && $store->get('pbx-alpha', $ids[49]) === $latest,
        'OS-enforced write exhaustion cannot replace the last valid revision or prune its chunks');
    $workers = [];
    foreach ([10002,10003] as $variant) {
        $workers[] = proc_open([PHP_BINARY, __FILE__, '--worker', $root, (string) $variant],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    }
    foreach ($workers as $process) { capacityCheck(is_resource($process) && proc_close($process) === 0, 'simultaneous capture completes under the shared journal lock'); }
    $entries = $store->list('pbx-alpha');
    capacityCheck(count($entries) === 50 && $entries[0]['sequence'] === $entries[1]['sequence'] + 1
        && $entries[0]['parentRevisionId'] === $entries[1]['revisionId'], 'same-second concurrent captures have deterministic sequence and parent ordering');
    $headId = $entries[0]['revisionId'];
    $store->pin('pbx-alpha', $ids[2], 'pinned-test', 'synthetic retention evidence');
    $ack = ['pbxId'=>'pbx-alpha','connectionId'=>'test-connection','blueprintId'=>'bp-test',
        'hash'=>$store->get('pbx-alpha',$ids[3])['contentHash'], 'version'=>4];
    $store->markPublished('pbx-alpha', $ids[3], $ack, 'test-connection');
    $store->setRetentionLimit(3);
    capacityCheck(count($store->list('pbx-alpha')) === 3, 'retention pruning completes without an aggregate-capacity failure');
    capacityCheck($store->get('pbx-alpha', $ids[2])['pins'] !== []
        && $store->get('pbx-alpha', $ids[3])['publicationAcknowledgement'] === $ack
        && $store->get('pbx-alpha', $headId)['revisionId'] === $headId,
        'pruning preserves pinned, acknowledged and latest revisions with their original references');
    mkdir($root . '/legacy', 0700);
    foreach ($legacy['revisions']['pbx-alpha'] as &$entry) {
        unset($entry['sequence'], $entry['recordHash']);
        $entry['recordHash'] = DesiredStatePlanner::fingerprint($entry);
    }
    unset($entry, $legacy['revisionSequences']);
    $legacyFile = $root . '/legacy/state.json';
    file_put_contents($legacyFile, json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($legacyFile,0600);
    $legacyDigest = hash_file('sha256',$legacyFile);
    $process = proc_open([PHP_BINARY,__FILE__,'--worker',$root . '/legacy','exhaust'],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    capacityCheck(is_resource($process) && proc_close($process) !== 0 && hash_file('sha256',$legacyFile) === $legacyDigest,
        'failed inline-to-chunk migration preserves the complete previous journal');
    $migrated = new RevisionStore(new JsonStateStore($root . '/legacy'));
    $migrated->capture(capacityBlueprint(20000));
    foreach ($legacy['revisions']['pbx-alpha'] as $id => $entry) {
        capacityCheck($migrated->get('pbx-alpha',$id) === $entry, 'inline migration preserves revision identity, hash, parent and metadata byte-equivalent values');
    }
    capacityCheck(json_decode(file_get_contents($legacyFile),true)['__respawnStorage'] === 'chunks.v1', 'legacy journal migrates atomically without resetting history');
    $maximum = capacityBlueprint(30000,49,2);
    capacityCheck(count(BuildSpec::plan($maximum)) === 100, 'supported maximum remains exactly 100 operations');
    $migrated->capture($maximum);
    $maximumStore = new RevisionStore(new JsonStateStore($root . '/maximum-profile'));
    for ($index=0; $index<50; $index++) { $maximumStore->capture(capacityBlueprint(40000+$index,49,2)); }
    capacityCheck(count($maximumStore->list('pbx-alpha')) === 50, '50 revisions also fit at the unchanged 100-operation configuration boundary');
    $maximumRetention = new RevisionStore(new JsonStateStore($root . '/maximum-retention'),500);
    for ($index=0; $index<500; $index++) { $maximumRetention->capture(SimulatedPbx::blueprint('pbx-alpha')); }
    capacityCheck(count($maximumRetention->list('pbx-alpha')) === 500, 'configured maximum 500 revisions is retained without an aggregate journal ceiling');
    $rejected = false;
    try { capacityBlueprint(30001,50,1); } catch (Throwable) { $rejected = true; }
    capacityCheck($rejected, '101-operation configuration remains rejected');
    $chunk = glob($root . '/legacy/chunks/*.json')[0];
    $original = file_get_contents($chunk);
    file_put_contents($chunk, '{}');
    $rejected = false;
    try { $migrated->list('pbx-alpha'); } catch (Throwable) { $rejected = true; }
    capacityCheck($rejected, 'corrupt immutable chunk fails closed without rewriting the journal');
    file_put_contents($chunk, $original);
    capacityCheck(count($migrated->list('pbx-alpha')) === 7, 'restoring exact chunk evidence permits safe restart recovery');
    $journal = new JsonStateStore($root . '/pending-journal');
    $pending = [];
    for ($index=0; $index<500; $index++) {
        $pending['rev-' . str_pad(dechex($index),32,'0',STR_PAD_LEFT)] = ['state'=>'publication_failed',
            'hash'=>hash('sha256',(string)$index),'attempts'=>12,'metadata'=>str_repeat('synthetic',300)];
    }
    $journal->update(static function (array &$state) use ($pending): void { $state['protection']['pbx-alpha']['outbox']=$pending; });
    capacityCheck((new JsonStateStore($root . '/pending-journal'))->update(static fn (array &$state): array => $state['protection']['pbx-alpha']['outbox']) === $pending,
        '500 pending publication records survive restart beyond the former aggregate journal capacity');
    $cron = file_get_contents(__DIR__ . '/../pond/respawn-protection.cron');
    capacityCheck(str_ends_with($cron, "\n") && preg_match('/^\* \* \* \* \* asterisk \/usr\/sbin\/fwconsole respawn protection-reconcile\n/', $cron) === 1,
        'cron.d specimen has valid field layout and terminating newline without suppressed diagnostics');
} finally { capacityRemove($root); }