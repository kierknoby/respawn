<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use Respawn\Reconstruction\Blueprint;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class LocalPondService
{
    private const CAPABILITIES = ['rehearsal','standby','recovery','manual_activation',
        'connectivity_equivalent','reconstruction.v2','apply.freepbx'];
    private const SCENARIOS = ['normal','accepted-response-lost','target-conflict','unresolved-secret',
        'apply-failure','worker-interruption','expired-readiness','cancel-during-work','partial-teardown',
        'shared-resource'];
    private JsonStateStore $store;

    public function __construct(private readonly string $root)
    {
        $this->store = new JsonStateStore($root . '/pond-state');
    }

    public function dispatch(array $envelope, string $token, string $endpoint): array
    {
        $this->validateEnvelope($envelope);
        $pbxId = $envelope['pbxId'];
        $connectionId = $envelope['connectionId'];
        $expectedConnection = 'local-pond-dev-v1:' . hash('sha256', json_encode([
            'adapter' => 'respawn.provider-rpc.v2', 'endpoint' => rtrim($endpoint, '/'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!hash_equals($expectedConnection, $connectionId)) { throw new RuntimeException('PROVIDER_CONNECTION_IDENTITY_MISMATCH'); }
        $this->authenticate($pbxId, $token);
        $parameters = $envelope['parameters'];
        $expected = match ($envelope['method']) {
            'capabilities','status' => [],
            'publish' => ['pbxId','payload','force','idempotencyKey'],
            'launch' => ['pbxId','blueprintId','blueprintVersion','intent','activationPolicy','idempotencyKey','requestedCapabilities'],
            'operation' => ['operationId'],
            'reconcile' => ['idempotencyKey'],
            'cancel' => ['operationId','expectedRevision'],
            'activate' => ['operationId','expectedRevision','confirmation'],
            'teardown' => ['operationId','expectedRevision','routingDisposition'],
            default => [],
        };
        if (array_diff($expected, array_keys($parameters)) || array_diff(array_keys($parameters), $expected)) {
            throw new RuntimeException('PROVIDER_REQUEST_INVALID');
        }
        return match ($envelope['method']) {
            'capabilities' => $this->capabilities($pbxId),
            'status' => $this->status($connectionId, $pbxId),
            'publish' => $this->publish($connectionId, $pbxId, $parameters),
            'launch' => $this->launch($connectionId, $pbxId, $parameters),
            'operation' => $this->getOperation($connectionId, $pbxId, $parameters),
            'reconcile' => $this->reconcile($connectionId, $pbxId, $parameters),
            'cancel' => $this->cancel($connectionId, $pbxId, $parameters),
            'activate' => $this->activate($connectionId, $pbxId, $parameters),
            'teardown' => $this->teardown($connectionId, $pbxId, $parameters),
            default => throw new RuntimeException('PROVIDER_METHOD_UNSUPPORTED'),
        };
    }

    public function setNextScenario(string $pbxId, string $connectionId, string $scenario): void
    {
        if (!in_array($scenario, self::SCENARIOS, true)) { throw new RuntimeException('LOCAL_DEMO_SCENARIO_INVALID'); }
        $this->store->update(function (array &$state) use ($pbxId, $connectionId, $scenario): void {
            $state['developmentScenarios'][$connectionId . ':' . $pbxId] = $scenario;
            $this->event($state, $pbxId, $connectionId, 'scenario_selected', ['scenario' => $scenario]);
        });
    }

    public function nextJob(): ?array
    {
        return $this->store->update(static function (array &$state): ?array {
            foreach ($state['jobs'] ?? [] as $operationId => &$job) {
                if (($job['state'] ?? '') !== 'pending') { continue; }
                $job['state'] = 'running';
                $job['startedAt'] ??= gmdate(DATE_ATOM);
                unset($job);
                return ['operationId' => $operationId, 'job' => $job ?? $state['jobs'][$operationId]];
            }
            unset($job);
            return null;
        });
    }

    public function recoverInterruptedJobs(): void
    {
        $this->store->update(function (array &$state): void {
            foreach ($state['jobs'] ?? [] as $operationId => &$job) {
                if (($job['state'] ?? '') !== 'running') { continue; }
                $job['state'] = 'pending';
                $job['recoveredAt'] = gmdate(DATE_ATOM);
                if (isset($state['providerConnections'][$job['connectionId']][$job['pbxId']]['operations'][$operationId])) {
                    $operation = &$state['providerConnections'][$job['connectionId']][$job['pbxId']]['operations'][$operationId];
                    $operation['development']['workerRecovery'] = 'resuming_persisted_checkpoint';
                    $this->touch($operation);
                    $this->event($state, $job['pbxId'], $job['connectionId'], 'worker_recovered', ['operationId' => $operationId]);
                    unset($operation);
                }
            }
            unset($job);
        });
    }

    public function workerOperation(string $operationId): ?array
    {
        return $this->store->update(static function (array &$state) use ($operationId): ?array {
            foreach ($state['providerConnections'] ?? [] as $connectionId => $pbxs) {
                foreach ($pbxs as $pbxId => $record) {
                    if (isset($record['operations'][$operationId])) {
                        return ['connectionId' => $connectionId, 'pbxId' => $pbxId, 'operation' => $record['operations'][$operationId],
                            'job' => $state['jobs'][$operationId] ?? null];
                    }
                }
            }
            return null;
        });
    }

    public function workerUpdate(string $operationId, callable $callback): ?array
    {
        return $this->store->update(function (array &$state) use ($operationId, $callback): ?array {
            $state['providerConnections'] ??= [];
            foreach ($state['providerConnections'] as $connectionId => &$pbxs) {
                foreach ($pbxs as $pbxId => &$record) {
                    if (!isset($record['operations'][$operationId])) { continue; }
                    $operation = &$record['operations'][$operationId];
                    $callback($operation, $state, $record);
                    $this->touch($operation);
                    unset($operation, $record, $pbxs);
                    return ['connectionId' => $connectionId, 'pbxId' => $pbxId];
                }
            }
            unset($record, $pbxs);
            return null;
        });
    }

    public function finishJob(string $operationId, bool $done, ?string $nextStage = null): void
    {
        $this->store->update(function (array &$state) use ($operationId, $done, $nextStage): void {
            $job = &$state['jobs'][$operationId];
            if (!isset($job)) { return; }
            if ($nextStage !== null) {
                $job['state'] = $nextStage === 'paused' ? 'paused' : 'pending';
                $job['stage'] = $nextStage;
                $job['updatedAt'] = gmdate(DATE_ATOM);
            } else {
                $job['state'] = $done ? 'complete' : 'failed';
                $job['completedAt'] = gmdate(DATE_ATOM);
            }
            unset($job);
        });
    }

    public function dashboardSnapshot(string $pbxId, string $connectionId, string $fixturePath, string $manifestPath): array
    {
        $fixture = (new SourceFixtureAdapter($fixturePath, $manifestPath))->read($pbxId);
        unset($fixture['secretResolver']);
        foreach ($fixture['devices'] as &$device) { $device['secretReference'] = 'protected source reference present'; unset($device['secretRef']); }
        unset($device);
        return $this->store->update(function (array &$state) use ($pbxId, $connectionId, $fixture): array {
            $record = $state['providerConnections'][$connectionId][$pbxId] ?? [];
            $operations = array_values($record['operations'] ?? []);
            return ['provider' => ['name' => 'Local Pond', 'environment' => 'DEVELOPMENT ONLY',
                    'connectionId' => $connectionId, 'connectionState' => 'loopback_http',
                    'enrolmentState' => 'authorised'],
                'source' => $fixture,
                'publication' => $record['publication'] ?? null,
                'operations' => array_map(fn (array $operation): array => $this->publicOperation($operation), $operations),
                'routingModel' => ['mode' => 'SIMULATED', 'activeDestinations' => $state['routingModel'][$pbxId] ?? []],
                'events' => array_values(array_filter($state['events'] ?? [], static fn (array $event): bool =>
                    ($event['pbxId'] ?? '') === $pbxId)),
                'nextScenario' => $state['developmentScenarios'][$connectionId . ':' . $pbxId] ?? 'normal'];
        });
    }

    public function routingProbe(string $pbxId, string $ringGroup): array
    {
        if (!preg_match('/^[0-9]{2,8}$/D', $ringGroup)) { throw new RuntimeException('ROUTING_MODEL_DESTINATION_INVALID'); }
        $active = $this->store->update(static fn (array &$state): ?array => $state['routingModel'][$pbxId] ?? null);
        if ($active === null) { return ['mode' => 'ROUTING-MODEL TEST', 'selected' => 'source:' . $pbxId,
            'ringGroup' => $ringGroup, 'extensionsAttempted' => [], 'state' => 'source_route_retained', 'liveCalls' => false]; }
        $context = $this->workerOperation($active['operationId']);
        if ($context === null || $context['pbxId'] !== $pbxId || $context['operation']['development']['targetId'] !== $active['destination']) {
            throw new RuntimeException('ROUTING_MODEL_OWNERSHIP_INVALID');
        }
        $targetRoot = $this->root . '/targets/' . hash('sha256', $active['operationId']);
        $target = new \Respawn\Reconstruction\SimulatedPbx(new JsonStateStore($targetRoot . '/objects'),
            $context['operation']['development']['targetId'], $active['operationId']);
        $objects = $target->snapshot()['objects'] ?? [];
        $groups = [];
        foreach ($objects as $id => $object) {
            if (str_starts_with($id, 'ringgroup:')) { $groups[substr($id, 10)] = $object['parameters']; }
        }
        $visited = [];
        $extensions = [];
        $cursor = $ringGroup;
        while ($cursor !== 'hangup') {
            if (isset($visited[$cursor]) || !isset($groups[$cursor])) { throw new RuntimeException('ROUTING_MODEL_DEPENDENCY_INVALID'); }
            $visited[$cursor] = true;
            $group = $groups[$cursor];
            foreach ($group['members'] as $member) { $extensions[] = $member; }
            $destination = $group['destination'];
            if ($destination === 'hangup') { break; }
            $cursor = $destination;
        }
        $groupSequence = array_keys($visited);
        return ['mode' => 'ROUTING-MODEL TEST', 'selected' => $active['destination'], 'operationId' => $active['operationId'],
            'ringGroupSequence' => $groupSequence, 'extensionsAttempted' => $extensions,
            'simulatedPrimaryOutcome' => 'no_answer',
            'fallbackRingGroup' => count($groupSequence) > 1 ? (string) $groupSequence[array_key_last($groupSequence)] : null,
            'selectedExtension' => $extensions[array_key_last($extensions)],
            'state' => 'model_route_resolved', 'liveCalls' => false, 'audioVerified' => false];
    }

    public function setCancellationRequested(string $operationId): void
    {
        $this->workerUpdate($operationId, function (array &$operation, array &$state): void {
            $operation['development']['cancelRequested'] = true;
            if (!in_array($operation['state'], ['active','torn_down','cancelled'], true)) {
                $operation['state'] = 'cancelling';
                $this->event($state, $operation['pbxId'], $operation['connectionId'], 'cancellation_requested', ['operationId' => $operation['operationId']]);
            }
        });
    }

    public function eventFor(string $operationId, string $name, array $safeDetails = []): void
    {
        $this->workerUpdate($operationId, function (array &$operation, array &$state) use ($name, $safeDetails): void {
            $this->recordWorkerEvent($state, $operation, $name, $safeDetails);
        });
    }

    public function recordWorkerEvent(array &$state, array $operation, string $name, array $safeDetails = []): void
    {
        $this->event($state, $operation['pbxId'], $operation['connectionId'], $name,
            ['operationId' => $operation['operationId']] + $safeDetails);
    }

    private function capabilities(string $pbxId): array
    {
        return ['providerId' => 'local-pond-development', 'identity' => $pbxId, 'authorised' => true,
            'capabilities' => self::CAPABILITIES, 'developmentOnly' => true];
    }

    private function status(string $connectionId, string $pbxId): array
    {
        return $this->store->update(function (array &$state) use ($connectionId, $pbxId): array {
            $record = $state['providerConnections'][$connectionId][$pbxId] ?? [];
            return ['publication' => $record['publication'] ?? null,
                'operations' => array_map(fn (array $operation): array => $this->publicOperation($operation),
                    array_values($record['operations'] ?? [])),
                'capabilities' => self::CAPABILITIES, 'authorised' => true,
                'enrolmentState' => 'authorised'];
        });
    }

    private function publish(string $connectionId, string $pbxId, array $parameters): array
    {
        if (($parameters['pbxId'] ?? '') !== $pbxId || !is_array($parameters['payload'] ?? null)
            || !is_bool($parameters['force'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $parameters['idempotencyKey'] ?? '')) {
            throw new RuntimeException('PROVIDER_PUBLICATION_ACK_INVALID');
        }
        $blueprint = $parameters['payload'];
        Blueprint::verifyHash($blueprint);
        Blueprint::ordered($blueprint);
        if (($blueprint['sourcePbxId'] ?? '') !== $pbxId) { throw new RuntimeException('PROVIDER_PUBLICATION_ACK_INVALID'); }
        return $this->store->update(function (array &$state) use ($connectionId, $pbxId, $parameters, $blueprint): array {
            $record = &$state['providerConnections'][$connectionId][$pbxId];
            $record ??= ['publication' => null, 'operations' => [], 'idempotency' => []];
            $priorKey = $record['publication']['attempts'][$parameters['idempotencyKey']] ?? null;
            if ($priorKey !== null && ($priorKey['hash'] ?? '') !== $blueprint['contentHash']) {
                throw new RuntimeException('PROVIDER_PUBLICATION_IDEMPOTENCY_CONFLICT');
            }
            if ($priorKey !== null && isset($priorKey['ack'])) { return ['state' => 'acknowledged', 'ack' => $priorKey['ack']]; }
            $previous = $record['publication']['ack'] ?? null;
            $version = $previous['version'] ?? 0;
            if ($previous === null || $previous['hash'] !== $blueprint['contentHash'] || $parameters['force']) { $version++; }
            $ack = ['pbxId' => $pbxId, 'connectionId' => $connectionId,
                'blueprintId' => 'bp-' . substr($blueprint['contentHash'], 0, 24), 'version' => $version,
                'hash' => $blueprint['contentHash'], 'at' => gmdate(DATE_ATOM)];
            $record['publication'] = ['export' => $blueprint, 'ack' => $ack,
                'attempt' => ['state' => 'acknowledged', 'hash' => $blueprint['contentHash'], 'at' => gmdate(DATE_ATOM)],
                'versions' => $record['publication']['versions'] ?? [],
                'attempts' => $record['publication']['attempts'] ?? []];
            $record['publication']['versions'][$version] = $blueprint;
            $record['publication']['attempts'][$parameters['idempotencyKey']] = ['hash' => $blueprint['contentHash'],
                'version' => $version, 'ack' => $ack];
            $this->event($state, $pbxId, $connectionId, 'blueprint_accepted', ['version' => $version, 'hash' => $blueprint['contentHash']]);
            return ['state' => 'acknowledged', 'ack' => $ack];
        });
    }

    private function launch(string $connectionId, string $pbxId, array $request): array
    {
        $required = ['pbxId','blueprintId','blueprintVersion','intent','activationPolicy','idempotencyKey','requestedCapabilities'];
        if (array_diff($required, array_keys($request)) || array_diff(array_keys($request), $required)
            || ($request['pbxId'] ?? '') !== $pbxId || !is_int($request['blueprintVersion'] ?? null)
            || !preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $request['idempotencyKey'] ?? '')
            || !in_array($request['intent'] ?? '', ['rehearsal','standby','recovery'], true)
            || !in_array($request['activationPolicy'] ?? '', ['none','manual'], true)
            || ($request['intent'] !== 'recovery' && $request['activationPolicy'] !== 'none')
            || !is_array($request['requestedCapabilities'] ?? null)
            || array_diff($request['requestedCapabilities'], self::CAPABILITIES)) {
            throw new RuntimeException('PROVIDER_OPERATION_INVALID');
        }
        return $this->store->update(function (array &$state) use ($connectionId, $pbxId, $request): array {
            $record = &$state['providerConnections'][$connectionId][$pbxId];
            $publication = $record['publication'] ?? null;
            $ack = $publication['ack'] ?? [];
            $blueprint = $publication['versions'][$request['blueprintVersion']] ?? null;
            if (($ack['blueprintId'] ?? '') !== $request['blueprintId'] || ($ack['version'] ?? 0) !== $request['blueprintVersion']
                || !is_array($blueprint)) { throw new RuntimeException('PROVIDER_PUBLICATION_REQUIRED'); }
            $idemKey = $pbxId . ':' . $request['idempotencyKey'];
            $requestHash = hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (isset($record['idempotency'][$idemKey])) {
                $prior = $record['idempotency'][$idemKey];
                if ($prior['requestHash'] !== $requestHash) { throw new RuntimeException('PROVIDER_IDEMPOTENCY_CONFLICT'); }
                return $this->publicOperation($record['operations'][$prior['operationId']]);
            }
            foreach ($record['operations'] ?? [] as $prior) {
                if (!in_array($prior['state'], ['torn_down'], true)) { throw new RuntimeException('PROVIDER_EXISTING_OPERATION_UNRESOLVED'); }
            }
            $scenarioKey = $connectionId . ':' . $pbxId;
            $scenario = $state['developmentScenarios'][$scenarioKey] ?? 'normal';
            unset($state['developmentScenarios'][$scenarioKey]);
            $id = 'op-' . bin2hex(random_bytes(12));
            $operation = ['operationId' => $id, 'connectionId' => $connectionId, 'pbxId' => $pbxId,
                'request' => $request, 'blueprint' => $blueprint, 'blueprintId' => $ack['blueprintId'],
                'blueprintVersion' => $ack['version'], 'state' => 'accepted', 'revision' => 1,
                'createdAt' => gmdate(DATE_ATOM), 'updatedAt' => gmdate(DATE_ATOM), 'resources' => [], 'errors' => [],
                'milestones' => ['accepted' => gmdate(DATE_ATOM)],
                'development' => ['verificationMode' => 'SIMULATED', 'scenario' => $scenario,
                    'targetId' => 'sim-' . substr($id, 3), 'targetObjects' => [], 'dependencyOrder' => [],
                    'reconstruction' => ['state' => 'pending'], 'apply' => ['state' => 'pending'],
                    'connectivity' => ['state' => 'not_prepared'],
                    'routing' => ['state' => 'not_prepared', 'destination' => 'source:' . $pbxId],
                    'readiness' => ['state' => 'SIMULATED_ONLY', 'activationAllowed' => false],
                    'remainingResources' => [], 'cancelRequested' => false]];
            $record['operations'][$id] = $operation;
            $record['idempotency'][$idemKey] = ['requestHash' => $requestHash, 'operationId' => $id];
            $state['jobs'][$id] = ['operationId' => $id, 'connectionId' => $connectionId,
                'pbxId' => $pbxId, 'stage' => 'provision', 'state' => 'pending', 'createdAt' => gmdate(DATE_ATOM)];
            $this->event($state, $pbxId, $connectionId, 'operation_accepted', ['operationId' => $id]);
            return $this->publicOperation($operation);
        });
    }

    private function getOperation(string $connectionId, string $pbxId, array $parameters): array
    {
        $id = $parameters['operationId'] ?? '';
        if (!is_string($id)) { throw new RuntimeException('OPERATION_ID_INVALID'); }
        $operation = $this->store->update(static fn (array &$state): ?array =>
            $state['providerConnections'][$connectionId][$pbxId]['operations'][$id] ?? null);
        if ($operation === null) { throw new RuntimeException('OPERATION_NOT_FOUND_ON_CONNECTION'); }
        return $this->publicOperation($operation);
    }

    private function reconcile(string $connectionId, string $pbxId, array $parameters): array
    {
        $key = $parameters['idempotencyKey'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $key)) { throw new RuntimeException('PROVIDER_RECONCILIATION_KEY_INVALID'); }
        $operation = $this->store->update(static function (array &$state) use ($connectionId, $pbxId, $key): ?array {
            $record = $state['providerConnections'][$connectionId][$pbxId] ?? [];
            $id = $record['idempotency'][$pbxId . ':' . $key]['operationId'] ?? null;
            return is_string($id) ? ($record['operations'][$id] ?? null) : null;
        });
        if ($operation === null) { return ['state' => 'not_found', 'authoritative' => true,
            'connectionId' => $connectionId, 'pbxId' => $pbxId, 'idempotencyKey' => $key]; }
        return $this->publicOperation($operation);
    }

    private function cancel(string $connectionId, string $pbxId, array $parameters): array
    {
        $id = $parameters['operationId'] ?? '';
        return $this->store->update(function (array &$state) use ($connectionId, $pbxId, $id, $parameters): array {
            $operation = &$state['providerConnections'][$connectionId][$pbxId]['operations'][$id];
            if (!isset($operation['operationId']) || ($parameters['expectedRevision'] ?? -1) !== $operation['revision']) {
                throw new RuntimeException('REVISION_CONFLICT');
            }
            if (in_array($operation['state'], ['active','torn_down'], true)) { throw new RuntimeException('OPERATION_CANCEL_STATE_INVALID'); }
            $operation['development']['cancelRequested'] = true;
            if (in_array($state['jobs'][$id]['state'] ?? '', ['pending','paused'], true)) {
                $operation['state'] = 'cancelled';
                $operation['milestones']['cancelled'] = gmdate(DATE_ATOM);
                $state['jobs'][$id]['state'] = 'complete';
            } else { $operation['state'] = 'cancelling'; }
            $this->touch($operation);
            $this->event($state, $pbxId, $connectionId, 'cancellation_requested', ['operationId' => $id]);
            return $this->publicOperation($operation);
        });
    }

    private function activate(string $connectionId, string $pbxId, array $parameters): array
    {
        $id = $parameters['operationId'] ?? '';
        return $this->store->update(function (array &$state) use ($connectionId, $pbxId, $parameters, $id): array {
            $operation = &$state['providerConnections'][$connectionId][$pbxId]['operations'][$id];
            if (!isset($operation['operationId']) || ($parameters['expectedRevision'] ?? -1) !== $operation['revision']) {
                throw new RuntimeException('REVISION_CONFLICT');
            }
            $readiness = $operation['development']['readiness'] ?? [];
            if ($operation['state'] !== 'routing_prepared' || $operation['request']['intent'] !== 'recovery'
                || $operation['request']['activationPolicy'] !== 'manual' || ($parameters['confirmation'] ?? '') !== 'activate:' . $id
                || ($readiness['state'] ?? '') !== 'SIMULATED_ONLY'
                || ($readiness['expiresAt'] ?? '') < gmdate(DATE_ATOM)) {
                throw new RuntimeException(($readiness['expiresAt'] ?? null) !== null && $readiness['expiresAt'] < gmdate(DATE_ATOM)
                    ? 'READINESS_EXPIRED' : 'SIMULATED_ACTIVATION_POLICY_REJECTED');
            }
            $targetId = $operation['development']['targetId'];
            $state['routingModel'][$pbxId] = ['destination' => $targetId, 'operationId' => $id,
                'activatedAt' => gmdate(DATE_ATOM), 'mode' => 'SIMULATED'];
            $operation['development']['routing'] = ['state' => 'active', 'destination' => $targetId,
                'mode' => 'SIMULATED', 'activatedAt' => gmdate(DATE_ATOM)];
            $operation['state'] = 'active';
            $operation['milestones']['simulated_activation'] = gmdate(DATE_ATOM);
            $this->touch($operation);
            $this->event($state, $pbxId, $connectionId, 'simulated_routing_activated', ['operationId' => $id, 'targetId' => $targetId]);
            return $this->publicOperation($operation);
        });
    }

    private function teardown(string $connectionId, string $pbxId, array $parameters): array
    {
        $id = $parameters['operationId'] ?? '';
        return $this->store->update(function (array &$state) use ($connectionId, $pbxId, $parameters, $id): array {
            $operation = &$state['providerConnections'][$connectionId][$pbxId]['operations'][$id];
            if (!isset($operation['operationId'])) { throw new RuntimeException('OPERATION_NOT_FOUND_ON_CONNECTION'); }
            if (($parameters['expectedRevision'] ?? -1) !== $operation['revision']) { throw new RuntimeException('REVISION_CONFLICT'); }
            if ($operation['state'] === 'active' && ($parameters['routingDisposition'] ?? '') !== 'operator-confirmed-deactivate') {
                throw new RuntimeException('ACTIVE_ROUTING_DISPOSITION_REQUIRED');
            }
            if (!in_array($operation['state'], ['active','routing_prepared','cancelled','failed','teardown_partial'], true)) {
                throw new RuntimeException('OPERATION_TEARDOWN_STATE_INVALID');
            }
            $targetRoot = $this->root . '/targets/' . hash('sha256', $id);
            $remainingObjects = [];
            if (is_dir($targetRoot . '/objects')) {
                $target = new \Respawn\Reconstruction\SimulatedPbx(new JsonStateStore($targetRoot . '/objects'),
                    $operation['development']['targetId'], $id);
                $remainingObjects = $target->cleanup();
                $snapshot = $target->snapshot();
            } else { $snapshot = ['objects' => []]; }
            if (($state['routingModel'][$pbxId]['operationId'] ?? '') === $id) { unset($state['routingModel'][$pbxId]); }
            $scenario = $operation['development']['scenario'];
            $resources = $operation['resources'];
            $failOnce = $scenario === 'partial-teardown' && empty($operation['development']['partialTeardownInjected']);
            $blocked = false;
            for ($index = count($resources) - 1; $index >= 0; $index--) {
                if (($resources[$index]['state'] ?? '') === 'removed') { continue; }
                if ($resources[$index]['shared'] ?? false) {
                    $resources[$index]['state'] = 'preserved_shared';
                    continue;
                }
                if ($failOnce && !$blocked) {
                    $resources[$index]['state'] = 'cleanup_failed';
                    $operation['development']['partialTeardownInjected'] = true;
                    $blocked = true;
                    continue;
                }
                if ($blocked) { $resources[$index]['state'] = 'cleanup_blocked_by_dependency'; continue; }
                $resources[$index]['state'] = 'removed';
            }
            $operation['resources'] = $resources;
            $preservedObjects = [];
            foreach ($remainingObjects as $objectId) {
                $object = $snapshot['objects'][$objectId] ?? [];
                $preservedObjects[] = ['id' => $objectId, 'owner' => $object['owner'] ?? 'unknown',
                    'shared' => ($object['shared'] ?? false) === true, 'state' => 'preserved_foreign_or_shared'];
            }
            $operation['development']['targetObjects'] = array_map(static fn (string $objectId): array => [
                'id' => $objectId, 'state' => $snapshot['objects'][$objectId]['state'] ?? 'unknown',
                'parameters' => $snapshot['objects'][$objectId]['parameters'] ?? [],
                'owner' => $snapshot['objects'][$objectId]['owner'] ?? 'unknown',
                'shared' => ($snapshot['objects'][$objectId]['shared'] ?? false) === true,
                'dependsOn' => $snapshot['objects'][$objectId]['dependsOn'] ?? []], $remainingObjects);
            $operation['development']['preservedSharedResources'] = $preservedObjects;
            $operation['development']['remainingResources'] = array_values(array_filter($resources,
                static fn (array $resource): bool => !in_array($resource['state'], ['removed','preserved_shared'], true)));
            $operation['state'] = $operation['development']['remainingResources'] === [] ? 'torn_down' : 'teardown_partial';
            $operation['milestones'][$operation['state']] = gmdate(DATE_ATOM);
            $this->touch($operation);
            $this->event($state, $pbxId, $connectionId, $operation['state'], ['operationId' => $id,
                'remainingCount' => count($operation['development']['remainingResources'])]);
            return $this->publicOperation($operation);
        });
    }

    private function authenticate(string $pbxId, string $token): void
    {
        $path = $this->root . '/credentials/enrolments.json';
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) { throw new RuntimeException('LOCAL_POND_ENROLMENT_STORE_UNSAFE'); }
        $records = json_decode((string) file_get_contents($path), true, 12, JSON_THROW_ON_ERROR);
        $expected = $records[$pbxId]['tokenHash'] ?? '';
        if (!is_string($expected) || !hash_equals($expected, hash('sha256', $token))
            || ($records[$pbxId]['state'] ?? '') !== 'authorised') { throw new RuntimeException('LOCAL_POND_AUTH_FAILED'); }
    }

    private function validateEnvelope(array $envelope): void
    {
        $required = ['schema','connectionId','method','pbxId','parameters'];
        if (array_diff($required, array_keys($envelope)) || array_diff(array_keys($envelope), $required)
            || ($envelope['schema'] ?? '') !== 'respawn.provider-rpc.v2'
            || !preg_match('/^local-pond-dev-v1:[a-f0-9]{64}$/D', $envelope['connectionId'] ?? '')
            || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $envelope['pbxId'] ?? '')
            || !is_array($envelope['parameters'] ?? null)
            || !in_array($envelope['method'] ?? '', ['capabilities','status','publish','launch','operation','reconcile','cancel','activate','teardown'], true)) {
            throw new RuntimeException('PROVIDER_REQUEST_INVALID');
        }
    }

    private function publicOperation(array $operation): array
    {
        unset($operation['blueprint']);
        return $operation;
    }

    private function event(array &$state, string $pbxId, string $connectionId, string $name, array $details = []): void
    {
        $safe = ['at' => gmdate(DATE_ATOM), 'pbxId' => $pbxId, 'connectionId' => $connectionId, 'event' => $name];
        foreach (['operationId','targetId','version','hash','scenario','remainingCount'] as $field) {
            if (isset($details[$field]) && is_scalar($details[$field])) { $safe[$field] = $details[$field]; }
        }
        $state['events'][] = $safe;
        if (count($state['events']) > 500) { $state['events'] = array_slice($state['events'], -500); }
    }

    private function touch(array &$operation): void
    {
        $operation['revision']++;
        $operation['updatedAt'] = gmdate(DATE_ATOM);
    }
}