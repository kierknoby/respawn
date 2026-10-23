<?php
declare(strict_types=1);
namespace Respawn\Provider;

interface RecoverableCredentialVault
{
    public function escrowCredential(string $sourcePbxId, string $secretReference, string $secret,
        string $idempotencyKey): array;

    public function retrieveCredential(string $sourcePbxId, string $secretReference): array;
}