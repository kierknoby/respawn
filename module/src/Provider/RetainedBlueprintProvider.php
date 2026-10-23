<?php
declare(strict_types=1);
namespace Respawn\Provider;

interface RetainedBlueprintProvider
{
    public function connectionIdentity(): string;

    public function retainRevision(string $pbxId, array $blueprint, string $revisionId, string $idempotencyKey): array;

    public function retrieveRevision(string $pbxId, string $revisionId): array;

    public function retentionEvidenceMode(): string;
}