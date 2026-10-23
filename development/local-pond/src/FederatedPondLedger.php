<?php
declare(strict_types=1);
namespace Respawn\LocalPond;

use Respawn\Reconstruction\Blueprint;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class FederatedPondLedger
{
    private JsonStateStore $store;
    private string $pondId;

    public function __construct(string $directory, string $initialPondId)
    {
        if (!preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $initialPondId)) {
            throw new RuntimeException('RIVER_POND_ID_INVALID');
        }
        $this->store = new JsonStateStore($directory);
        $this->pondId = $this->store->update(static function (array &$state) use ($initialPondId): string {
            $state['federation'] ??= ['pondId' => $initialPondId, 'agreements' => [], 'replicas' => [], 'transfers' => []];
            if (($state['federation']['pondId'] ?? '') !== $initialPondId) {
                throw new RuntimeException('RIVER_POND_IDENTITY_CONFLICT');
            }
            return $state['federation']['pondId'];
        });
    }

    public function pondId(): string { return $this->pondId; }

    public function approvePeer(string $federationId, string $peerPondId, string $tenantId,
        array $sourcePbxIds, array $allowedMethods, string $approvedBy): array
    {
        if (!preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $federationId)
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $peerPondId) || $peerPondId === $this->pondId
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $tenantId)
            || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $approvedBy)
            || !$sourcePbxIds || array_filter($sourcePbxIds, static fn ($id): bool => !is_string($id)
                || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $id))
            || !$allowedMethods || array_diff($allowedMethods, ['blueprint.replication.v1'])) {
            throw new RuntimeException('RIVER_AGREEMENT_INVALID');
        }
        $sourcePbxIds = array_values(array_unique($sourcePbxIds));
        $allowedMethods = array_values(array_unique($allowedMethods));
        sort($sourcePbxIds, SORT_STRING);
        sort($allowedMethods, SORT_STRING);
        $agreement = ['federationId' => $federationId, 'localPondId' => $this->pondId,
            'peerPondId' => $peerPondId, 'tenantId' => $tenantId, 'sourcePbxIds' => $sourcePbxIds,
            'allowedMethods' => $allowedMethods, 'state' => 'approved', 'approvedBy' => $approvedBy,
            'approvedAt' => gmdate(DATE_ATOM)];
        return $this->store->update(function (array &$state) use ($federationId, $peerPondId, $agreement): array {
            $prior = $state['federation']['agreements'][$federationId] ?? null;
            if ($prior !== null && (($prior['peerPondId'] ?? '') !== $peerPondId
                || ($prior['tenantId'] ?? '') !== $agreement['tenantId']
                || ($prior['sourcePbxIds'] ?? []) !== $agreement['sourcePbxIds']
                || ($prior['allowedMethods'] ?? []) !== $agreement['allowedMethods'])) {
                throw new RuntimeException('RIVER_AGREEMENT_SCOPE_IMMUTABLE');
            }
            $state['federation']['agreements'][$federationId] = $agreement;
            return $agreement;
        });
    }

    public function revokePeer(string $federationId, string $revokedBy): array
    {
        if (!preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $revokedBy)) { throw new RuntimeException('RIVER_REVOCATION_ACTOR_INVALID'); }
        return $this->store->update(function (array &$state) use ($federationId, $revokedBy): array {
            $agreement = &$state['federation']['agreements'][$federationId];
            if (!isset($agreement['federationId']) || ($agreement['localPondId'] ?? '') !== $this->pondId) {
                throw new RuntimeException('RIVER_AGREEMENT_NOT_FOUND');
            }
            $agreement['state'] = 'revoked';
            $agreement['revokedBy'] = $revokedBy;
            $agreement['revokedAt'] = gmdate(DATE_ATOM);
            return $agreement;
        });
    }

    public function queueRevision(string $federationId, string $tenantId, string $sourcePbxId,
        string $revisionId, array $blueprint, ?string $parentRevisionId = null): array
    {
        self::verifyBlueprint($blueprint);
        if (($blueprint['sourcePbxId'] ?? '') !== $sourcePbxId
            || !preg_match('/^rev-[a-f0-9]{32}$/D', $revisionId)
            || ($parentRevisionId !== null && (!preg_match('/^rev-[a-f0-9]{32}$/D', $parentRevisionId)
                || $parentRevisionId === $revisionId))) {
            throw new RuntimeException('RIVER_REVISION_BINDING_INVALID');
        }
        $agreement = $this->agreement($federationId, $tenantId, $sourcePbxId, 'blueprint.replication.v1');
        $key = hash('sha256', implode("\0", [$this->pondId, $agreement['peerPondId'], $federationId,
            $tenantId, $sourcePbxId, $revisionId, $parentRevisionId ?? '', $blueprint['contentHash']]));
        $transactionId = 'xfer-' . $key;
        return $this->store->update(function (array &$state) use ($agreement, $federationId, $tenantId,
            $sourcePbxId, $revisionId, $parentRevisionId, $blueprint, $key, $transactionId): array {
            $transfer = $state['federation']['transfers'][$transactionId] ?? null;
            if ($transfer !== null) {
                if (($transfer['contentHash'] ?? '') !== $blueprint['contentHash']) {
                    throw new RuntimeException('RIVER_IDEMPOTENCY_CONFLICT');
                }
                return $transfer;
            }
            $transfer = ['schema' => 'respawn.river-federation.v1', 'transactionId' => $transactionId,
                'idempotencyKey' => $key, 'federationId' => $federationId, 'tenantId' => $tenantId,
                'senderPondId' => $this->pondId, 'recipientPondId' => $agreement['peerPondId'],
                'sourcePbxId' => $sourcePbxId, 'revisionId' => $revisionId, 'parentRevisionId' => $parentRevisionId,
                'contentHash' => $blueprint['contentHash'], 'blueprint' => $blueprint,
                'state' => 'queued', 'attempts' => 0, 'queuedAt' => gmdate(DATE_ATOM)];
            $state['federation']['transfers'][$transactionId] = $transfer;
            return $transfer;
        });
    }

    public function receiveReplica(array $transfer, string $assertedPeerPondId): array
    {
        $this->validateTransfer($transfer, $assertedPeerPondId);
        $agreement = $this->agreement($transfer['federationId'], $transfer['tenantId'],
            $transfer['sourcePbxId'], 'blueprint.replication.v1');
        if ($assertedPeerPondId !== $agreement['peerPondId']
            || ($transfer['recipientPondId'] ?? '') !== $this->pondId) {
            throw new RuntimeException('RIVER_PEER_OR_RECIPIENT_MISMATCH');
        }
        self::verifyBlueprint($transfer['blueprint']);
        if (($transfer['blueprint']['sourcePbxId'] ?? '') !== $transfer['sourcePbxId']
            || ($transfer['blueprint']['contentHash'] ?? '') !== $transfer['contentHash']) {
            throw new RuntimeException('RIVER_BLUEPRINT_INTEGRITY_FAILED');
        }
        $replicaKey = $transfer['tenantId'] . ':' . $transfer['sourcePbxId'] . ':' . $transfer['revisionId'];
        return $this->store->update(function (array &$state) use ($transfer, $replicaKey): array {
            $existing = $state['federation']['replicas'][$replicaKey] ?? null;
            if ($existing !== null) {
                if (($existing['contentHash'] ?? '') !== $transfer['contentHash']
                    || ($existing['transactionId'] ?? '') !== $transfer['transactionId']) {
                    throw new RuntimeException('RIVER_REPLICA_CONFLICT');
                }
                return $existing['receipt'];
            }
            $receipt = ['schema' => 'respawn.river-receipt.v1', 'state' => 'durably_retained_simulated',
                'federationId' => $transfer['federationId'], 'transactionId' => $transfer['transactionId'],
                'idempotencyKey' => $transfer['idempotencyKey'], 'senderPondId' => $transfer['senderPondId'],
                'recipientPondId' => $this->pondId, 'tenantId' => $transfer['tenantId'],
                'sourcePbxId' => $transfer['sourcePbxId'], 'revisionId' => $transfer['revisionId'],
                'parentRevisionId' => $transfer['parentRevisionId'] ?? null,
                'contentHash' => $transfer['contentHash'], 'replicaId' => 'rep-' . substr(hash('sha256', $replicaKey), 0, 32),
                'retainedAt' => gmdate(DATE_ATOM), 'mode' => 'SIMULATED'];
            $state['federation']['replicas'][$replicaKey] = ['sourcePondId' => $transfer['senderPondId'],
                'transactionId' => $transfer['transactionId'], 'contentHash' => $transfer['contentHash'],
                'revisionId' => $transfer['revisionId'], 'parentRevisionId' => $transfer['parentRevisionId'] ?? null,
                'blueprint' => $transfer['blueprint'],
                'receipt' => $receipt, 'replicatedAt' => gmdate(DATE_ATOM)];
            return $receipt;
        });
    }

    public function transferQueued(FederatedPondLedger $recipient, string $transactionId): array
    {
        $transfer = $this->store->update(static fn (array &$state): ?array =>
            $state['federation']['transfers'][$transactionId] ?? null);
        if (!is_array($transfer)) { throw new RuntimeException('RIVER_TRANSFER_NOT_FOUND'); }
        if (($transfer['recipientPondId'] ?? '') !== $recipient->pondId()) {
            throw new RuntimeException('RIVER_RECIPIENT_IDENTITY_MISMATCH');
        }
        try {
            $receipt = $recipient->receiveReplica($transfer, $this->pondId);
            $this->recordReceipt($transactionId, $receipt);
            return $receipt;
        } catch (\Throwable $exception) {
            $this->store->update(static function (array &$state) use ($transactionId, $exception): void {
                $state['federation']['transfers'][$transactionId]['state'] = 'retryable';
                $state['federation']['transfers'][$transactionId]['attempts']++;
                $state['federation']['transfers'][$transactionId]['lastError'] =
                    preg_match('/^[A-Z0-9_]{3,64}$/D', $exception->getMessage()) ? $exception->getMessage() : 'RIVER_TRANSFER_FAILED';
                $state['federation']['transfers'][$transactionId]['lastAttemptAt'] = gmdate(DATE_ATOM);
            });
            throw $exception;
        }
    }

    public function recordReceipt(string $transactionId, array $receipt): array
    {
        return $this->store->update(function (array &$state) use ($transactionId, $receipt): array {
            $transfer = &$state['federation']['transfers'][$transactionId];
            if (!isset($transfer['transactionId']) || ($receipt['transactionId'] ?? '') !== $transactionId
                || ($receipt['schema'] ?? '') !== 'respawn.river-receipt.v1'
                || !in_array($receipt['state'] ?? '', ['durably_retained','durably_retained_simulated'], true)
                || !in_array($receipt['mode'] ?? '', ['EXTERNAL','SIMULATED'], true)
                || (($receipt['state'] ?? '') === 'durably_retained_simulated' && ($receipt['mode'] ?? '') !== 'SIMULATED')
                || ($receipt['federationId'] ?? '') !== ($transfer['federationId'] ?? '')
                || ($receipt['senderPondId'] ?? '') !== $this->pondId
                || ($receipt['recipientPondId'] ?? '') !== ($transfer['recipientPondId'] ?? '')
                || ($receipt['tenantId'] ?? '') !== ($transfer['tenantId'] ?? '')
                || ($receipt['sourcePbxId'] ?? '') !== ($transfer['sourcePbxId'] ?? '')
                || ($receipt['revisionId'] ?? '') !== ($transfer['revisionId'] ?? '')
                || ($receipt['parentRevisionId'] ?? null) !== ($transfer['parentRevisionId'] ?? null)
                || ($receipt['contentHash'] ?? '') !== ($transfer['contentHash'] ?? '')
                || ($receipt['idempotencyKey'] ?? '') !== ($transfer['idempotencyKey'] ?? '')
                || !is_string($receipt['replicaId'] ?? null)) {
                throw new RuntimeException('RIVER_RECEIPT_BINDING_INVALID');
            }
            $transfer['receipt'] = $receipt;
            $transfer['state'] = ($receipt['mode'] ?? '') === 'SIMULATED'
                ? 'replica_confirmed_simulated' : 'replica_confirmed';
            $transfer['confirmedAt'] = gmdate(DATE_ATOM);
            return $transfer;
        });
    }

    public function retrieveReplica(string $tenantId, string $sourcePbxId, string $revisionId,
        string $assertedRequesterPondId): array
    {
        if ($assertedRequesterPondId !== $this->pondId) {
            throw new RuntimeException('RIVER_REPLICA_RETRIEVAL_UNAUTHORISED');
        }
        $replicaKey = $tenantId . ':' . $sourcePbxId . ':' . $revisionId;
        $replica = $this->store->update(static fn (array &$state): ?array =>
            $state['federation']['replicas'][$replicaKey] ?? null);
        if (!is_array($replica)) { throw new RuntimeException('RIVER_REPLICA_NOT_FOUND'); }
        self::verifyBlueprint($replica['blueprint']);
        if (($replica['blueprint']['sourcePbxId'] ?? '') !== $sourcePbxId
            || ($replica['blueprint']['contentHash'] ?? '') !== ($replica['contentHash'] ?? '')) {
            throw new RuntimeException('RIVER_REPLICA_INTEGRITY_FAILED');
        }
        return ['pondId' => $this->pondId, 'tenantId' => $tenantId, 'sourcePbxId' => $sourcePbxId,
            'revisionId' => $revisionId, 'contentHash' => $replica['contentHash'],
            'blueprint' => $replica['blueprint'], 'receipt' => $replica['receipt'], 'mode' => 'SIMULATED'];
    }

    public function agreements(): array
    {
        return $this->store->update(static fn (array &$state): array => array_values($state['federation']['agreements'] ?? []));
    }

    public function transfers(): array
    {
        return $this->store->update(static fn (array &$state): array => $state['federation']['transfers'] ?? []);
    }

    private function agreement(string $federationId, string $tenantId, string $sourcePbxId, string $method): array
    {
        $agreement = $this->store->update(static fn (array &$state): ?array =>
            $state['federation']['agreements'][$federationId] ?? null);
        if (!is_array($agreement) || ($agreement['state'] ?? '') !== 'approved'
            || ($agreement['localPondId'] ?? '') !== $this->pondId
            || ($agreement['tenantId'] ?? '') !== $tenantId
            || !in_array($sourcePbxId, $agreement['sourcePbxIds'] ?? [], true)
            || !in_array($method, $agreement['allowedMethods'] ?? [], true)) {
            throw new RuntimeException('RIVER_SCOPE_NOT_AUTHORISED');
        }
        return $agreement;
    }

    private function validateTransfer(array $transfer, string $assertedPeerPondId): void
    {
        if (($transfer['schema'] ?? '') !== 'respawn.river-federation.v1'
            || ($transfer['senderPondId'] ?? '') !== $assertedPeerPondId
            || !preg_match('/^xfer-[a-f0-9]{64}$/D', $transfer['transactionId'] ?? '')
            || ($transfer['transactionId'] ?? '') !== 'xfer-' . ($transfer['idempotencyKey'] ?? '')
            || !is_string($transfer['tenantId'] ?? null) || !is_string($transfer['sourcePbxId'] ?? null)
            || !is_string($transfer['revisionId'] ?? null) || !preg_match('/^rev-[a-f0-9]{32}$/D', $transfer['revisionId'])
            || (($transfer['parentRevisionId'] ?? null) !== null
                && !preg_match('/^rev-[a-f0-9]{32}$/D', $transfer['parentRevisionId']))
            || !preg_match('/^[a-f0-9]{64}$/D', $transfer['contentHash'] ?? '')
            || !is_array($transfer['blueprint'] ?? null)) {
            throw new RuntimeException('RIVER_ENVELOPE_INVALID');
        }
        $expectedKey = hash('sha256', implode("\0", [$transfer['senderPondId'], $transfer['recipientPondId'],
            $transfer['federationId'], $transfer['tenantId'], $transfer['sourcePbxId'], $transfer['revisionId'],
            $transfer['parentRevisionId'] ?? '', $transfer['contentHash']]));
        if (($transfer['idempotencyKey'] ?? '') !== $expectedKey || ($transfer['transactionId'] ?? '') !== 'xfer-' . $expectedKey) {
            throw new RuntimeException('RIVER_TRANSACTION_BINDING_INVALID');
        }
    }

    private static function verifyBlueprint(array $blueprint): void
    {
        try {
            Blueprint::verifyHash($blueprint);
            Blueprint::ordered($blueprint);
        } catch (\Throwable) {
            throw new RuntimeException('RIVER_BLUEPRINT_INTEGRITY_FAILED');
        }
    }
}
