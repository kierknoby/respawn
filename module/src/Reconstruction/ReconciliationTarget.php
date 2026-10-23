<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

interface ReconciliationTarget extends BuildTarget
{
    public function reconciliationCapabilities(): array;
    public function managedOwner(): string;
    public function reconciliationSnapshot(SecretResolver $secrets, string $sourcePbxId, array $operationCatalog = []): array;
    public function update(array $operation, SecretResolver $secrets, string $sourcePbxId): void;
    public function remove(array $operation, SecretResolver $secrets, string $sourcePbxId): void;
}