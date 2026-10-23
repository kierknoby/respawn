<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

use RuntimeException;

final class MemorySecrets implements SecretResolver
{
    public function __construct(private array $values) {}
    public function reference(string $sourcePbxId, string $number): string
    {
        $prefix = 'secret:' . $sourcePbxId . ':' . $number . ':';
        $matches = array_values(array_filter(array_keys($this->values), static fn (string $key): bool => str_starts_with($key, $prefix)));
        if (count($matches) !== 1) { throw new RuntimeException('SECRET_REVISION_UNRESOLVED'); }
        return $matches[0];
    }
    public function resolve(string $reference, string $sourcePbxId): string
    {
        if (!str_starts_with($reference, 'secret:' . $sourcePbxId . ':') || !isset($this->values[$reference])) {
            throw new RuntimeException('SECRET_UNRESOLVED');
        }
        return $this->values[$reference];
    }
}