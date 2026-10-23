<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

interface BuildTarget
{
    public function identity(): string;
    public function environment(): array;
    public function preflight(array $operations): void;
    public function inspect(array $operation, SecretResolver $secrets, string $sourcePbxId): array;
    public function create(array $operation, SecretResolver $secrets, string $sourcePbxId): void;
    public function apply(): array;
}