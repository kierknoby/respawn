<?php
declare(strict_types=1);

namespace Respawn\Provider;

interface Provider
{
    public function connectionIdentity(): string;

    public function capabilities(): array;

    public function status(): array;

    public function publish(string $pbxId, array $intent, bool $force = false): array;

    public function launch(array $request): array;

    public function getOperation(string $operationId): array;

    public function reconcile(string $idempotencyKey): array;

    public function advance(string $operationId, ?string $failAt = null): array;

    public function cancel(string $operationId): array;

    public function activate(string $operationId, int $revision, string $confirmation): array;

    public function teardown(string $operationId, ?string $routingDisposition = null): array;
}