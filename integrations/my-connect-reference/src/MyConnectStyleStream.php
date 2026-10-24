<?php
declare(strict_types=1);
namespace Respawn\Integration\MyConnectReference;

use Respawn\Provider\HttpsProvider;
use Respawn\Provider\OrderedRetainedBlueprintProvider;
use Respawn\Provider\RecoverableCredentialVault;

final class MyConnectStyleStream implements OrderedRetainedBlueprintProvider, RecoverableCredentialVault
{
    public function __construct(private readonly HttpsProvider $transport) {}

    public function connectionIdentity(): string { return $this->transport->connectionIdentity(); }

    public function retainRevision(string $pbxId, array $blueprint, string $revisionId, string $idempotencyKey): array
    {
        return $this->transport->retainRevision($pbxId, $blueprint, $revisionId, $idempotencyKey);
    }

    public function retainOrderedRevision(string $pbxId, array $blueprint, string $revisionId,
        string $idempotencyKey, ?string $parentRevisionId): array
    {
        return $this->transport->retainOrderedRevision($pbxId, $blueprint, $revisionId, $idempotencyKey, $parentRevisionId);
    }

    public function retrieveRevision(string $pbxId, string $revisionId): array
    {
        return $this->transport->retrieveRevision($pbxId, $revisionId);
    }

    public function retrieveLatestRevision(string $pbxId): array
    {
        return $this->transport->retrieveLatestRevision($pbxId);
    }

    public function retentionEvidenceMode(): string { return $this->transport->retentionEvidenceMode(); }

    public function escrowCredential(string $sourcePbxId, string $secretReference, string $secret,
        string $idempotencyKey): array
    {
        return $this->transport->escrowCredential($sourcePbxId, $secretReference, $secret, $idempotencyKey);
    }

    public function retrieveCredential(string $sourcePbxId, string $secretReference): array
    {
        return $this->transport->retrieveCredential($sourcePbxId, $secretReference);
    }
}