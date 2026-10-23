<?php
declare(strict_types=1);
namespace Respawn\Provider;

interface OrderedRetainedBlueprintProvider extends RetainedBlueprintProvider
{
    public function retainOrderedRevision(string $pbxId, array $blueprint, string $revisionId,
        string $idempotencyKey, ?string $parentRevisionId): array;

    public function retrieveLatestRevision(string $pbxId): array;
}