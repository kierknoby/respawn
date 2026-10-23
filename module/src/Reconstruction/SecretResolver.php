<?php
declare(strict_types=1);
namespace Respawn\Reconstruction;

interface SecretResolver
{
    public function reference(string $sourcePbxId, string $number): string;
    public function resolve(string $reference, string $sourcePbxId): string;
}