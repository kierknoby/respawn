<?php
declare(strict_types=1);
namespace Respawn\Provider;

use Respawn\Reconstruction\Blueprint;
use Respawn\Reconstruction\FixtureTarget;
use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\BuildSpec;
use Respawn\Reconstruction\Executor;
use Respawn\Reconstruction\MemorySecrets;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Reconstruction\SecretResolver;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class MockProvider implements Provider, RetainedBlueprintProvider
{
    private const STAGES = ['accepted', 'provisioning', 'configuration', 'connectivity', 'routing_prepared', 'ready'];

    private string $connection;

    public function __construct(private JsonStateStore $store, private string $identity, private bool $online = true,
        private ?SecretResolver $secrets = null, ?string $connectionIdentity = null)
    {
        $this->connection = $connectionIdentity ?? ConnectionIdentity::forConfiguration([], 'mock');
    }

    public function connectionIdentity(): string { return $this->connection; }

    public function capabilities(): array
    {
        $this->available();
        return ['mode' => 'MOCK', 'identity' => $this->identity, 'authorised' => true,
            'capabilities' => ['rehearsal', 'standby', 'recovery', 'manual_activation', 'connectivity_equivalent', 'reconstruction.v2', 'apply.freepbx'],
            'realInfrastructure' => false];
    }

    public function publish(string $pbxId, array $intent, bool $force = false): array
    {
        $this->bind($pbxId);
        if (($intent['schema'] ?? '') === 'respawn.firewall-intent.v1') {
            $this->bind($intent['pbxId']);
            $canonical = \Respawn\Domain\FirewallIntent::create($pbxId, $intent['intent']['enabled'], $intent['intent']['sources'], $intent['generatedAt']);
            if ($canonical !== $intent) { throw new RuntimeException('Invalid canonical firewall publication.'); }
        } else {
            Blueprint::verifyHash($intent);
            Blueprint::ordered($intent);
            $this->bind($intent['sourcePbxId']);
        }
        $connection = $this->connection;
        return $this->store->update(function (array &$state) use ($intent, $force, $connection): array {
            $entry = &$state['providerConnections'][$connection]['publications'][$this->identity];
            $entry ??= [];
            $entry['export'] = $intent;
            if (!$force && ($entry['ack']['hash'] ?? '') === $intent['contentHash']) {
                return ['mode' => 'MOCK', 'state' => 'unchanged', 'ack' => $entry['ack']];
            }
            $entry['attempt'] = ['at' => gmdate(DATE_ATOM), 'pbxId' => $this->identity,
                'connectionId' => $connection, 'hash' => $intent['contentHash'], 'state' => 'failed'];
            if (!$this->online) {
                return ['mode' => 'MOCK', 'state' => 'failed', 'retryable' => true];
            }
            $entry['ack'] = ['pbxId' => $this->identity, 'connectionId' => $connection,
                'blueprintId' => 'bp-' . $this->identity, 'version' => ($entry['ack']['version'] ?? 0) + 1,
                'hash' => $intent['contentHash'], 'at' => gmdate(DATE_ATOM)];
            $entry['attempt']['state'] = 'acknowledged';
            return ['mode' => 'MOCK', 'state' => 'acknowledged', 'ack' => $entry['ack']];
        });
    }

    public function retainRevision(string $pbxId, array $blueprint, string $revisionId, string $idempotencyKey): array
    {
        $this->available();
        $this->bind($pbxId);
        Blueprint::verifyHash($blueprint);
        Blueprint::ordered($blueprint);
        if (($blueprint['sourcePbxId'] ?? '') !== $pbxId
            || !preg_match('/^rev-[a-f0-9]{32}$/D', $revisionId)
            || !preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey)) {
            throw new RuntimeException('PROTECTION_PUBLICATION_BINDING_INVALID');
        }
        return $this->store->update(function (array &$state) use ($pbxId, $blueprint, $revisionId, $idempotencyKey): array {
            $entry = &$state['retainedBlueprints'][$this->connection][$pbxId][$revisionId];
            if (isset($entry) && ($entry['contentHash'] ?? '') !== $blueprint['contentHash']) {
                throw new RuntimeException('PROTECTION_REVISION_ID_CONFLICT');
            }
            if (!isset($entry)) {
                $version = (int) ($state['retainedBlueprintVersions'][$this->connection][$pbxId] ?? 0) + 1;
                $publicationId = 'pub-' . substr(hash('sha256', $this->connection . "\0" . $pbxId . "\0" . $revisionId), 0, 32);
                $entry = ['blueprint' => $blueprint, 'contentHash' => $blueprint['contentHash'],
                    'idempotencyKey' => $idempotencyKey, 'publicationId' => $publicationId,
                    'version' => $version, 'retainedAt' => gmdate(DATE_ATOM)];
                $state['retainedBlueprintVersions'][$this->connection][$pbxId] = $version;
            } elseif (($entry['idempotencyKey'] ?? '') !== $idempotencyKey) {
                throw new RuntimeException('PROTECTION_IDEMPOTENCY_CONFLICT');
            }
            $ack = ['pbxId' => $pbxId, 'connectionId' => $this->connection, 'revisionId' => $revisionId,
                'blueprintId' => 'bp-' . substr($blueprint['contentHash'], 0, 24), 'publicationId' => $entry['publicationId'],
                'idempotencyKey' => $idempotencyKey, 'version' => $entry['version'], 'hash' => $blueprint['contentHash'],
                'retentionReceipt' => 'mock-retained:' . $entry['publicationId'], 'mode' => 'SIMULATED'];
            $entry['acknowledgement'] = $ack;
            return ['state' => 'durably_retained', 'ack' => $ack];
        });
    }

    public function retrieveRevision(string $pbxId, string $revisionId): array
    {
        $this->available();
        $this->bind($pbxId);
        $connection = $this->connection;
        $entry = $this->store->update(static fn (array &$state): ?array =>
            $state['retainedBlueprints'][$connection][$pbxId][$revisionId] ?? null);
        if (!is_array($entry)) { throw new RuntimeException('PROTECTION_RETAINED_REVISION_NOT_FOUND'); }
        return ['pbxId' => $pbxId, 'connectionId' => $this->connection, 'revisionId' => $revisionId,
            'publicationId' => $entry['publicationId'], 'blueprint' => $entry['blueprint'], 'mode' => 'SIMULATED'];
    }

    public function retentionEvidenceMode(): string { return 'SIMULATED'; }

    public function launch(array $request): array
    {
        $this->available();
        $this->bind($request['pbxId'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D', $request['idempotencyKey'] ?? '')
            || !in_array($request['intent'] ?? '', ['rehearsal', 'standby', 'recovery'], true)
            || !in_array($request['activationPolicy'] ?? '', ['none', 'manual'], true)
            || ($request['intent'] !== 'recovery' && $request['activationPolicy'] !== 'none')
            || array_diff($request['requestedCapabilities'] ?? [], $this->capabilities()['capabilities'])) {
            throw new RuntimeException('Invalid operation request or unsupported capabilities.');
        }
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        $connection = $this->connection;
        return $this->store->update(function (array &$state) use ($request, $hash, $connection): array {
            $key = $this->identity . ':' . $request['idempotencyKey'];
            if (isset($state['providerConnections'][$connection]['idempotency'][$key])) {
                $previous = $state['providerConnections'][$connection]['idempotency'][$key];
                if ($hash !== $previous['hash']) { throw new RuntimeException('Idempotency conflict.'); }
                return $state['providerConnections'][$connection]['operations'][$previous['id']];
            }
            $publication = $state['providerConnections'][$connection]['publications'][$this->identity] ?? [];
            $ack = $publication['ack'] ?? [];
            if (($ack['connectionId'] ?? '') !== $connection || ($ack['pbxId'] ?? '') !== $this->identity) {
                throw new RuntimeException('PUBLICATION_CONNECTION_BINDING_INVALID');
            }
            if (!in_array($publication['export']['schema'] ?? '', ['respawn.reconstruction.v1','respawn.reconstruction.v2'], true)) {
                throw new RuntimeException('Firewall publication alone is not a reconstruction blueprint.');
            }
            if (($request['blueprintId'] ?? '') !== ($ack['blueprintId'] ?? null)
                || ($request['blueprintVersion'] ?? 0) !== ($ack['version'] ?? null)) {
                throw new RuntimeException('A current acknowledged reconstruction blueprint is required.');
            }
            foreach ($state['providerConnections'][$connection]['operations'] ?? [] as $existing) {
                if ($existing['pbxId'] === $this->identity && $existing['state'] !== 'torn_down') {
                    throw new RuntimeException('Reconcile or tear down the existing operation first.');
                }
            }
            $id = 'op-' . bin2hex(random_bytes(12));
            $operation = ['mode' => 'MOCK', 'connectionId' => $connection, 'operationId' => $id, 'pbxId' => $this->identity,
                'request' => $request, 'blueprint' => $publication['export'], 'state' => 'accepted', 'revision' => 1,
                'resources' => [], 'errors' => [], 'milestones' => ['invoked' => gmdate(DATE_ATOM)],
                'updatedAt' => gmdate(DATE_ATOM), 'simulated' => true, 'realCallsVerified' => false];
            $state['providerConnections'][$connection]['operations'][$id] = $operation;
            $state['providerConnections'][$connection]['idempotency'][$key] = ['id' => $id, 'hash' => $hash];
            return $operation;
        });
    }

    public function getOperation(string $operationId): array
    {
        return $this->mutate($operationId, static function (array &$operation): void {}, false);
    }

    public function reconcile(string $idempotencyKey): array
    {
        $this->available();
        $id = $this->store->update(function (array &$state) use ($idempotencyKey): string {
            $entry = $state['providerConnections'][$this->connection]['idempotency'][$this->identity . ':' . $idempotencyKey] ?? null;
            if (!$entry) { return ['state' => 'not_found', 'authoritative' => true, 'connectionId' => $this->connection,
                'pbxId' => $this->identity, 'idempotencyKey' => $idempotencyKey]; }
            return $entry['id'];
        });
        return is_array($id) ? $id : $this->getOperation($id);
    }

    public function advance(string $operationId, ?string $failAt = null): array
    {
        $initial = $this->getOperation($operationId);
        $build = null;
        if ($initial['state'] === 'provisioning' && $failAt !== 'configuration') {
            $session = $this->store->child('reconstruction:' . $operationId);
            if ($initial['blueprint']['schema'] === 'respawn.reconstruction.v2') {
                $values = $this->store->child('synthetic-secrets:' . $this->identity)->update(static function (array &$state) use ($initial): array {
                    foreach (BuildSpec::plan($initial['blueprint']) as $instruction) {
                        foreach ($instruction['secretRefs'] as $reference) { $state['syntheticSecrets'][$reference] ??= bin2hex(random_bytes(24)); }
                    }
                    return $state['syntheticSecrets'] ?? [];
                });
                $target = new SimulatedPbx($session->child('objects'), 'mock-' . $operationId, $operationId);
                $build = (new BuildExecutor($target, $session->child('journal'), $this->secrets ?? new MemorySecrets($values)))->execute($initial['blueprint'], true);
            } else {
                $target = new FixtureTarget('legacy-' . $operationId);
                $build = (new Executor($target, $session->child('legacy-journal')))->execute($initial['blueprint'], true);
                $build['legacyGroups'] = $target->groups;
            }
        }
        return $this->mutate($operationId, static function (array &$operation) use ($failAt, $initial, $build): void {
            if ($operation['revision'] !== $initial['revision']) { throw new RuntimeException('STALE_OPERATION_TRANSITION'); }
            $index = array_search($operation['state'], self::STAGES, true);
            if ($index === false || !isset(self::STAGES[$index + 1])) { throw new RuntimeException('Cannot advance this state.'); }
            $next = self::STAGES[$index + 1];
            if ($next === $failAt) {
                $operation['state'] = 'failed';
                $operation['errors'][] = ['stage' => $next, 'code' => 'MOCK_FAILURE'];
                return;
            }
            if ($next === 'configuration') {
                $operation['reconstruction'] = $build;
                if (($build['state'] ?? '') !== 'configuration_verified') {
                    $operation['state'] = ($build['state'] ?? '') === 'unknown' ? 'unknown' : 'failed';
                    $operation['errors'][] = ['stage' => 'configuration', 'code' => 'RECONSTRUCTION_NOT_VERIFIED'];
                    return;
                }
                $operation['reconstructedGroups'] = $build['legacyGroups'] ?? [];
                $operation['configurationEvidence'] = ['mode' => 'SIMULATED',
                    'verifiedObjects' => isset($build['operations']) ? count($build['operations']) : count($build['legacyGroups'] ?? []),
                    'apply' => $build['apply']['state'] ?? 'not_implemented_in_legacy_v1'];
            }
            if ($next === 'ready') {
                $operation['readiness'] = ['mode' => 'SIMULATED', 'selectedCoverage' => $operation['blueprint']['coverage'],
                    'reconstruction' => $operation['reconstruction']['state'] ?? 'unknown',
                    'apply' => $operation['reconstruction']['apply']['state'] ?? 'unverified_legacy',
                    'configurationMatch' => ($operation['reconstruction']['state'] ?? '') === 'configuration_verified',
                    'connectivity' => 'simulated_prepared', 'routing' => 'simulated_prepared',
                    'registration' => 'unverified', 'inboundCall' => 'unverified', 'outboundCall' => 'unverified',
                    'bidirectionalAudio' => 'unverified', 'serviceReady' => false];
            }
            $operation['state'] = $next;
            $operation['milestones'][$next] = gmdate(DATE_ATOM);
            if (in_array($next, ['provisioning', 'connectivity', 'routing_prepared'], true)) {
                $operation['resources'][] = ['id' => $operation['operationId'] . ':' . $next,
                    'owner' => $operation['operationId'], 'shared' => false, 'state' => 'owned',
                    'dependsOn' => array_column($operation['resources'], 'id')];
            }
        });
    }

    public function activate(string $id, int $revision, string $confirmation): array
    {
        return $this->mutate($id, static function (array &$operation) use ($id, $revision, $confirmation): void {
            if ($operation['revision'] !== $revision || $operation['state'] !== 'ready'
                || $operation['request']['intent'] !== 'recovery' || $operation['request']['activationPolicy'] !== 'manual'
                || $confirmation !== 'activate:' . $id) { throw new RuntimeException('Unsafe or stale activation request.'); }
            $operation['state'] = 'active';
            $operation['milestones']['activated'] = gmdate(DATE_ATOM);
        });
    }

    public function cancel(string $operationId): array
    {
        return $this->mutate($operationId, static function (array &$operation): void {
            if (!in_array($operation['state'], [...self::STAGES, 'failed', 'unknown', 'cancelled'], true)) {
                throw new RuntimeException('Cannot cancel active or cleaned-up operations.');
            }
            $operation['state'] = 'cancelled';
        });
    }

    public function teardown(string $operationId, ?string $routingDisposition = null, ?string $failResource = null): array
    {
        $result = $this->mutate($operationId, static function (array &$operation) use ($routingDisposition, $failResource): void {
            if ($operation['state'] === 'torn_down') { return; }
            if ($operation['state'] === 'active' && $routingDisposition !== 'operator-confirmed-deactivate') {
                throw new RuntimeException('Active routing needs explicit disposition; no automatic failback.');
            }
            if (!in_array($operation['state'], ['active', 'cancelled', 'failed', 'unknown', 'ready', 'teardown_partial'], true)) {
                throw new RuntimeException('Cancel unfinished work first.');
            }
            $blocked = false;
            for ($index = count($operation['resources']) - 1; $index >= 0; $index--) {
                $resource = &$operation['resources'][$index];
                if ($resource['state'] === 'removed') { continue; }
                if ($blocked || $resource['owner'] !== $operation['operationId'] || $resource['shared'] || $resource['id'] === $failResource) {
                    $resource['state'] = 'cleanup-failed';
                    $blocked = true;
                } else { $resource['state'] = 'removed'; }
            }
            $operation['state'] = $blocked ? 'teardown_partial' : 'torn_down';
            $operation['remainingResources'] = array_values(array_filter($operation['resources'], static fn (array $resource): bool => $resource['state'] !== 'removed'));
        });
        if ($result['state'] === 'torn_down' && ($result['blueprint']['schema'] ?? '') === 'respawn.reconstruction.v2') {
            $target = new SimulatedPbx($this->store->child('reconstruction:' . $operationId)->child('objects'), 'mock-' . $operationId, $operationId);
            if ($target->cleanup() !== []) {
                return $this->mutate($operationId, static function (array &$operation): void {
                    $operation['state'] = 'teardown_partial';
                    $operation['errors'][] = ['stage' => 'teardown', 'code' => 'SHARED_OR_FOREIGN_TARGET_OBJECTS_RETAINED'];
                });
            }
        }
        return $result;
    }

    public function status(): array
    {
        $this->available();
        return $this->store->update(function (array &$state): array {
            $connection = $state['providerConnections'][$this->connection] ?? [];
            $legacyPublication = $state['publications'][$this->identity] ?? null;
            $legacyOperations = array_values(array_filter($state['operations'] ?? [], fn (array $operation): bool =>
                ($operation['pbxId'] ?? '') === $this->identity && !isset($operation['connectionId'])));
            return ['mode' => 'MOCK', 'connectionId' => $this->connection,
                'publication' => $connection['publications'][$this->identity] ?? null,
                'operations' => array_values(array_filter($connection['operations'] ?? [], fn (array $operation): bool => $operation['pbxId'] === $this->identity)),
                'quarantinedLegacy' => ['publicationCount' => $legacyPublication === null ? 0 : 1,
                    'operationIds' => array_values(array_filter(array_map(static fn (array $operation): ?string =>
                        is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null, $legacyOperations))),
                    'requiresExplicitReconciliation' => $legacyPublication !== null || $legacyOperations !== []]];
        });
    }

    private function mutate(string $id, callable $callback, bool $write = true): array
    {
        $this->available();
        $connection = $this->connection;
        return $this->store->update(function (array &$state) use ($id, $callback, $write, $connection): array {
            if (!isset($state['providerConnections'][$connection]['operations'][$id])) { throw new RuntimeException('OPERATION_NOT_FOUND_ON_CONNECTION'); }
            $operation = &$state['providerConnections'][$connection]['operations'][$id];
            if (($operation['connectionId'] ?? '') !== $connection) { throw new RuntimeException('OPERATION_CONNECTION_MISMATCH'); }
            $this->bind($operation['pbxId']);
            $callback($operation);
            if ($write) { $operation['revision']++; $operation['updatedAt'] = gmdate(DATE_ATOM); }
            return $operation;
        });
    }
    private function bind(string $identity): void { if ($identity !== $this->identity) { throw new RuntimeException('PBX identity mismatch.'); } }
    private function available(): void { if (!$this->online) { throw new RuntimeException('Provider unavailable; cached status is stale, not authoritative.'); } }
}