<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';
require __DIR__ . '/../pond/Pond.php';

use Respawn\Pond\Pond;
use Respawn\Provider\ConnectionIdentity;
use Respawn\Provider\HttpsProvider;
use Respawn\Protection\ProtectionReconciler;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Reconstruction\RevisionStore;
use Respawn\Storage\JsonStateStore;

function orderingCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}
function orderingRemove(string $path): void
{
    if (!is_dir($path) || is_link($path)) { if (file_exists($path)) { unlink($path); } return; }
    foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { orderingRemove($path . '/' . $entry); } }
    rmdir($path);
}
$root = sys_get_temp_dir() . '/respawn-ordering-' . bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    mkdir($root . '/pond',0700);
    file_put_contents($root . '/key',random_bytes(32)); chmod($root . '/key',0600);
    $pond = new Pond($root . '/pond',$root . '/key');
    $token = bin2hex(random_bytes(32));
    $config = ['schema'=>'respawn.provider-config.v1','adapterId'=>'ordering-test','endpoint'=>'https://pond.example.invalid/rpc',
        'sourcePbxId'=>'pbx-alpha','allowedHosts'=>['pond.example.invalid'],'credentialRef'=>$root . '/unused.token'];
    $connection = ConnectionIdentity::forConfiguration($config);
    $pond->enrol($token,'tenant-test','pbx-alpha',$connection,['retain','read','escrow','recover']);
    $call = static function (string $method, array $parameters) use (&$pond,$token,$connection): array {
        return $pond->dispatch($token,['schema'=>'respawn.provider-rpc.v2','connectionId'=>$connection,'pbxId'=>'pbx-alpha',
            'method'=>$method,'parameters'=>$parameters,'requestId'=>bin2hex(random_bytes(16)),'sentAt'=>gmdate(DATE_ATOM)]);
    };
    $blueprint = SimulatedPbx::blueprint('pbx-alpha');
    foreach ($blueprint['operations'] as $operation) {
        foreach ($operation['secretRefs'] as $reference) {
            $call('escrowCredential',['sourcePbxId'=>'pbx-alpha','secretReference'=>$reference,
                'secret'=>'Synthetic-Ordering-Password','idempotencyKey'=>hash('sha256',$reference)]);
        }
    }
    $retain = static function (string $id, ?string $parent, bool $ordered=true) use ($call,$blueprint,$connection): array {
        $params = ['pbxId'=>'pbx-alpha','revisionId'=>$id,'payload'=>$blueprint,'contentHash'=>$blueprint['contentHash'],
            'idempotencyKey'=>hash('sha256',$connection . "\0pbx-alpha\0" . $id . "\0" . $blueprint['contentHash'])];
        if ($ordered) { $params['ordering'] = ['parentRevisionId'=>$parent]; }
        return $call('retainRevision',$params);
    };
    $revisionA = 'rev-' . str_repeat('a',32); $revisionB = 'rev-' . str_repeat('b',32);
    $revisionC = 'rev-' . str_repeat('c',32); $historical = 'rev-' . str_repeat('d',32);
    $legacy = 'rev-' . str_repeat('e',32);
    $receiptA = $retain($revisionA,null);
    orderingCheck($retain($revisionB,$revisionA)['recoveryHeadRevisionId'] === $revisionB,'B advances only by naming current head A as its parent');
    orderingCheck($retain($revisionA,null)['ack'] === $receiptA['ack']
        && $call('latestRevision',[])['revisionId'] === $revisionB,'duplicate A preserves its immutable receipt and cannot roll back B');
    orderingCheck($retain($revisionC,$revisionB)['recoveryHeadRevisionId'] === $revisionC,'C advances after B');
    orderingCheck($retain($historical,$revisionA)['recoveryHeadRevisionId'] === $revisionC
        && $call('retrieveRevision',['revisionId'=>$historical])['revisionId'] === $historical,
        'delayed historical revision is retained without replacing C');
    orderingCheck($retain($legacy,null,false)['recoveryHeadRevisionId'] === $revisionC,
        'legacy unordered submission remains retrievable but cannot promote the recovery head');
    $rejected = false;
    try { $retain($historical,$revisionC); } catch (Throwable) { $rejected = true; }
    orderingCheck($rejected && $call('latestRevision',[])['revisionId'] === $revisionC,
        'conflicting lineage cannot rebind a retained revision and promote it');
    unset($pond); $pond = new Pond($root . '/pond',$root . '/key');
    orderingCheck($call('latestRevision',[])['revisionId'] === $revisionC,'restart preserves authoritative recovery head independently of acceptance order');
    $transport = static function (array $request) use (&$pond,$token): array {
        return ['schema'=>'respawn.provider-rpc.v2','connectionId'=>$request['connectionId'],'pbxId'=>'pbx-alpha',
            'method'=>$request['method'],'ok'=>true,'observedAt'=>gmdate(DATE_ATOM),'expiresAt'=>gmdate(DATE_ATOM,time()+60),
            'result'=>$pond->dispatch($token,$request)];
    };
    $provider = new HttpsProvider($config,$transport);
    $coordinator = new ProtectionReconciler(new JsonStateStore($root . '/restored'),
        new RevisionStore(new JsonStateStore($root . '/restored-revisions')), $provider,'pbx-alpha');
    $rejected = false;
    try { $coordinator->authoriseResume($revisionA); } catch (Throwable) { $rejected = true; }
    orderingCheck($rejected,'restored source cannot resume against an obsolete recovery head');
    $coordinator->authoriseResume($revisionC);
    $resumed = $coordinator->reconcile(static fn (): array => $blueprint,true);
    orderingCheck($resumed['latestVerifiedRevisionId'] === $resumed['latestLocalRevisionId']
        && $provider->retrieveLatestRevision('pbx-alpha')['revisionId'] === $resumed['latestLocalRevisionId'],
        'explicitly authorised source recovery continues current history without resetting it');
    $future = 'rev-' . str_repeat('f',32);
    $retain($future,$resumed['latestLocalRevisionId']);
    $coordinator->authoriseResume($future);
    orderingCheck($coordinator->status(true)['latestVerifiedRevisionId'] === null,
        'resume authorisation invalidates stale local protection instead of claiming an older receipt is current');
    $again = $coordinator->reconcile(static fn (): array => $blueprint,true);
    orderingCheck($again['latestVerifiedRevisionId'] !== $resumed['latestVerifiedRevisionId']
        && $again['latestVerifiedRevisionId'] === $provider->retrieveLatestRevision('pbx-alpha')['revisionId'],
        'unchanged restored configuration creates a fresh authorised child, not replay of an obsolete transaction');
    $rejected = false;
    try { $provider->retainOrderedRevision('pbx-alpha',$blueprint,$historical,
        hash('sha256',$connection . "\0pbx-alpha\0" . $historical . "\0" . $blueprint['contentHash']),$revisionA); }
    catch (Throwable) { $rejected = true; }
    orderingCheck($rejected,'Stream adapter cannot treat a retained historical revision as current protection');
    $database = new PDO('sqlite:' . $root . '/pond/pond.sqlite');
    $scope = hash('sha256',"tenant-test\0pbx-alpha");
    $database->prepare('UPDATE objects SET body=? WHERE scope=? AND kind=? AND id=?')->execute([
        json_encode(['revisionId'=>$legacy,'version'=>77],JSON_THROW_ON_ERROR),$scope,'head','latest']);
    unset($database,$pond);
    $pond = new Pond($root . '/pond',$root . '/key');
    orderingCheck($call('latestRevision',[])['revisionId'] === $legacy,'pre-extension recovery head is preserved on database restart without rewriting receipts');
    orderingCheck($retain($revisionA,null)['ack'] === $receiptA['ack']
        && $call('latestRevision',[])['revisionId'] === $legacy,'legacy-head compatibility cannot be used to roll back by replaying an older receipt');
    echo "Pond ordering tests passed; direct authenticated SQLite service contract, not live FreePBX.\n";
} finally { orderingRemove($root); }