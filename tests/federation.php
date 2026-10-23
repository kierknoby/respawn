<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';
require __DIR__ . '/../development/local-pond/bootstrap.php';

use Respawn\LocalPond\FederatedPondLedger;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;

function federationCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
}

function federationReject(callable $callback, string $code, string $message): void
{
    try { $callback(); }
    catch (RuntimeException $exception) {
        if ($exception->getMessage() !== $code) { throw $exception; }
        federationCheck(true, $message);
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

function federationRemoveTree(string $path): void
{
    if (is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) { if (file_exists($path)) { unlink($path); } return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') { federationRemoveTree($path . '/' . $entry); }
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/respawn-river-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    foreach (['pond-a','pond-b','pond-c','pond-unauthorised'] as $name) { mkdir($root . '/' . $name, 0700); }
    $pondA = new FederatedPondLedger($root . '/pond-a', 'pond-dev-a');
    $pondB = new FederatedPondLedger($root . '/pond-b', 'pond-dev-b');
    $pondC = new FederatedPondLedger($root . '/pond-c', 'pond-dev-c');
    $unauthorised = new FederatedPondLedger($root . '/pond-unauthorised', 'pond-dev-untrusted');
    $federationAB = 'fed-a-b-1';
    $federationAC = 'fed-a-c-1';
    $tenant = 'tenant-test';
    $method = ['blueprint.replication.v1'];
    $pondA->approvePeer($federationAB, $pondB->pondId(), $tenant, ['pbx-alpha'], $method, 'synthetic-operator-a');
    $pondB->approvePeer($federationAB, $pondA->pondId(), $tenant, ['pbx-alpha'], $method, 'synthetic-operator-b');
    federationReject(fn () => $pondA->approvePeer($federationAB, $pondB->pondId(), $tenant,
        ['pbx-alpha','pbx-bravo'], $method, 'synthetic-operator-a'),
        'RIVER_AGREEMENT_SCOPE_IMMUTABLE', 'federation ID cannot silently acquire wider PBX scope');
    federationCheck($pondA->pondId() === 'pond-dev-a' && $pondB->pondId() === 'pond-dev-b'
        && $pondA->pondId() !== $pondB->pondId(), 'each independently persisted Pond has a stable distinct identity');

    $blueprintA = SimulatedPbx::blueprint('pbx-alpha');
    $revisionA = 'rev-' . str_repeat('a', 32);
    $transferA = $pondA->queueRevision($federationAB, $tenant, 'pbx-alpha', $revisionA, $blueprintA);
    federationCheck($transferA['state'] === 'queued' && $transferA['senderPondId'] === $pondA->pondId()
        && $transferA['recipientPondId'] === $pondB->pondId(), 'queued River transaction binds both Pond identities and PBX scope');
    $receiptA = $pondA->transferQueued($pondB, $transferA['transactionId']);
    federationCheck($receiptA['state'] === 'durably_retained_simulated'
        && $receiptA['contentHash'] === $blueprintA['contentHash'],
        'authorised peer accepts and acknowledges the exact blueprint hash');
    $replicaA = $pondB->retrieveReplica($tenant, 'pbx-alpha', $revisionA, $pondB->pondId());
    federationCheck($replicaA['blueprint'] === $blueprintA && $replicaA['mode'] === 'SIMULATED',
        'receiving Pond independently retrieves and verifies its own retained blueprint copy');
    federationReject(fn () => $pondB->retrieveReplica($tenant, 'pbx-alpha', $revisionA, $unauthorised->pondId()),
        'RIVER_REPLICA_RETRIEVAL_UNAUTHORISED', 'replica retrieval rejects a different unauthorised Pond identity');
    federationReject(fn () => $pondB->retrieveReplica('tenant-other', 'pbx-alpha', $revisionA, $pondB->pondId()),
        'RIVER_REPLICA_NOT_FOUND', 'cross-tenant replica lookup does not reveal retained data');

    $revisionB = 'rev-' . str_repeat('b', 32);
    $blueprintB = SimulatedPbx::blueprint('pbx-bravo');
    federationReject(fn () => $pondA->queueRevision($federationAB, $tenant, 'pbx-bravo', $revisionB, $blueprintB),
        'RIVER_SCOPE_NOT_AUTHORISED', 'federation relationship rejects an out-of-scope PBX');
    federationReject(fn () => $unauthorised->receiveReplica($transferA, $pondA->pondId()),
        'RIVER_SCOPE_NOT_AUTHORISED', 'Pond without a bilateral agreement rejects an asserted peer identity');

    $revisionC = 'rev-' . str_repeat('c', 32);
    $transferC = $pondA->queueRevision($federationAB, $tenant, 'pbx-alpha', $revisionC, $blueprintA, $revisionA);
    $lostReceipt = $pondB->receiveReplica($transferC, $pondA->pondId());
    federationCheck($lostReceipt['replicaId'] !== ''
        && $pondB->retrieveReplica($tenant, 'pbx-alpha', $revisionC, $pondB->pondId())['blueprint'] === $blueprintA,
        'destination durably retains before the sender records its receipt');
    federationCheck(($lostReceipt['parentRevisionId'] ?? '') === $revisionA,
        'replicated revision preserves its source revision parent link');
    $receiptReplay = $pondA->transferQueued($pondB, $transferC['transactionId']);
    federationCheck($receiptReplay['replicaId'] === $lostReceipt['replicaId']
        && ($pondA->transfers()[$transferC['transactionId']]['state'] ?? '') === 'replica_confirmed_simulated',
        'lost acknowledgement retry reuses the same transaction and replica without duplication');

    $tampered = $transferA;
    $tampered['blueprint']['operations'][0]['parameters']['tampered'] = true;
    federationReject(fn () => $pondB->receiveReplica($tampered, $pondA->pondId()),
        'RIVER_BLUEPRINT_INTEGRITY_FAILED', 'receiver rejects altered blueprint bytes');

    $transferC3 = 'fed-a-c-1';
    $pondA->approvePeer($transferC3, $pondC->pondId(), $tenant, ['pbx-alpha'], $method, 'synthetic-operator-a');
    $thirdTransfer = $pondA->queueRevision($transferC3, $tenant, 'pbx-alpha', $revisionA, $blueprintA);
    federationReject(fn () => $pondA->transferQueued($pondC, $thirdTransfer['transactionId']),
        'RIVER_SCOPE_NOT_AUTHORISED', 'one-sided federation approval is insufficient');
    $pondC->approvePeer($transferC3, $pondA->pondId(), $tenant, ['pbx-alpha'], $method, 'synthetic-operator-c');
    $thirdReceipt = $pondA->transferQueued($pondC, $thirdTransfer['transactionId']);
    federationCheck($pondB->pondId() !== $pondC->pondId()
        && $pondC->retrieveReplica($tenant, 'pbx-alpha', $revisionA, $pondC->pondId())['blueprint']['contentHash'] === $blueprintA['contentHash']
        && $thirdReceipt['contentHash'] === $receiptA['contentHash'],
        'adding a third Pond preserves identities and replicates without changing blueprint identity');

    $pondB = new FederatedPondLedger($root . '/pond-b', 'pond-dev-b');
    federationCheck($pondB->pondId() === 'pond-dev-b'
        && $pondB->retrieveReplica($tenant, 'pbx-alpha', $revisionA, $pondB->pondId())['blueprint'] === $blueprintA,
        'replica survives receiving-Pond process restart');
    $pondA->revokePeer($federationAB, 'synthetic-operator-a');
    federationReject(fn () => $pondA->queueRevision($federationAB, $tenant, 'pbx-alpha',
        'rev-' . str_repeat('e', 32), $blueprintA), 'RIVER_SCOPE_NOT_AUTHORISED',
        'revoked federation blocks new transfers');
    federationCheck($pondB->retrieveReplica($tenant, 'pbx-alpha', $revisionA, $pondB->pondId())['blueprint'] === $blueprintA,
        'revocation stops new transfers without deleting an authorised existing replica');
    federationRemoveTree($root . '/pond-a');
    federationCheck($pondB->retrieveReplica($tenant, 'pbx-alpha', $revisionA, $pondB->pondId())['blueprint'] === $blueprintA,
        'authorised replica remains retrievable after original Pond storage is removed');
    federationCheck($pondB->pondId() === 'pond-dev-b' && $pondC->pondId() === 'pond-dev-c',
        'Pond identities remain independent of original Puddle/Stream and sibling Ponds');
    echo "Federation tests passed. Two independent JSON stores and peer authentication are synthetic; no mTLS, encryption, River service or Lake is implemented.\n";
} finally { federationRemoveTree($root); }
