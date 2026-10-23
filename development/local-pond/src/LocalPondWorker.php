<?php
declare(strict_types=1);

namespace Respawn\LocalPond;

use Respawn\Reconstruction\BuildExecutor;
use Respawn\Reconstruction\FileSecrets;
use Respawn\Reconstruction\SimulatedPbx;
use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class LocalPondWorker
{
    public function __construct(private readonly LocalPondService $service, private readonly string $root) {}

    public function runOne(): bool
    {
        $claimed = $this->service->nextJob();
        if ($claimed === null) { return false; }
        $context = $this->service->workerOperation($claimed['operationId']);
        if ($context === null) { $this->service->finishJob($claimed['operationId'], false); return true; }
        try {
            $next = match ($claimed['job']['stage']) {
                'provision' => $this->provision($context),
                'reconstruct' => $this->reconstruct($context),
                'connectivity' => $this->connectivity($context),
                'routing' => $this->routing($context),
                default => throw new RuntimeException('LOCAL_POND_WORK_STAGE_INVALID'),
            };
            if ($next === null) { $this->service->finishJob($claimed['operationId'], true); }
            else { $this->service->finishJob($claimed['operationId'], false, $next); }
        } catch (\Throwable $exception) {
            $fresh = $this->service->workerOperation($claimed['operationId']);
            if ($fresh !== null) {
                $cancelled = !empty($fresh['operation']['development']['cancelRequested']);
                $this->service->workerUpdate($claimed['operationId'], function (array &$operation, array &$state) use ($exception, $cancelled): void {
                    $operation['state'] = $cancelled ? 'cancelled' : 'failed';
                    $operation['errors'][] = ['stage' => 'worker', 'code' => $this->safeCode($exception->getMessage())];
                    $operation['development']['workerState'] = $cancelled ? 'cancelled_at_checkpoint' : 'failed';
                    $operation['milestones'][$operation['state']] = gmdate(DATE_ATOM);
                    $this->service->recordWorkerEvent($state, $operation, $cancelled ? 'operation_cancelled' : 'worker_failed');
                });
            }
            $this->service->finishJob($claimed['operationId'], true);
        }
        return true;
    }

    private function provision(array $context): ?string
    {
        $id = $context['operation']['operationId'];
        $this->service->workerUpdate($id, function (array &$operation, array &$state): void {
            if ($operation['development']['cancelRequested']) { $operation['state'] = 'cancelled'; return; }
            $operation['state'] = 'provisioning';
            $this->ensureResource($operation, 'replacement', false);
            $operation['milestones']['replacement_allocated'] = gmdate(DATE_ATOM);
            $operation['development']['replacement'] = ['id' => $operation['development']['targetId'],
                'state' => 'allocated', 'mode' => 'SIMULATED'];
            if ($operation['development']['scenario'] === 'cancel-during-work') {
                $operation['development']['workerState'] = 'paused_at_operator_cancellation_checkpoint';
            }
            $this->service->recordWorkerEvent($state, $operation, 'replacement_allocated', ['targetId' => $operation['development']['targetId']]);
        });
        $updated = $this->service->workerOperation($id);
        if (($updated['operation']['state'] ?? '') === 'cancelled') { return null; }
        return ($updated['operation']['development']['scenario'] ?? '') === 'cancel-during-work' ? 'paused' : 'reconstruct';
    }

    private function reconstruct(array $context): ?string
    {
        $operation = $context['operation'];
        $id = $operation['operationId'];
        if (!empty($operation['development']['cancelRequested'])) {
            $this->service->workerUpdate($id, function (array &$current, array &$state): void {
                $current['state'] = 'cancelled';
                $current['milestones']['cancelled'] = gmdate(DATE_ATOM);
                $this->service->recordWorkerEvent($state, $current, 'operation_cancelled');
            });
            return null;
        }
        $targetRoot = $this->targetRoot($id);
        if (!is_dir($targetRoot) && !mkdir($targetRoot, 0700, true) && !is_dir($targetRoot)) { throw new RuntimeException('LOCAL_DEMO_TARGET_CREATE_FAILED'); }
        chmod($targetRoot, 0700);
        $objects = new JsonStateStore($targetRoot . '/objects');
        $target = new SimulatedPbx($objects, $operation['development']['targetId'], $id);
        $scenario = $operation['development']['scenario'];
        if ($scenario === 'target-conflict') { $this->seedConflict($target, $operation); }
        if ($scenario === 'shared-resource') { $this->seedSharedObject($target, $operation); }
        $secrets = $scenario === 'unresolved-secret'
            ? new FileSecrets($this->root . '/credentials/missing-manifest.json')
            : new FileSecrets($this->root . '/credentials/' . $context['pbxId'] . '.manifest.json');
        $journal = new JsonStateStore($targetRoot . '/checkpoints');
        $target->failApply = $scenario === 'apply-failure';
        $this->service->workerUpdate($id, function (array &$current): void {
            $current['state'] = 'configuration';
            $current['development']['replacement']['state'] = 'reconstructing';
            $current['milestones']['reconstruction_started'] = gmdate(DATE_ATOM);
        });
        $observer = function (string $event, string $objectId) use ($id, $target, $scenario): void {
            if ($event !== 'after_mutation') { return; }
            $snapshot = $target->snapshot();
            $objectsView = $this->safeObjects($snapshot['objects'] ?? []);
            $order = array_values($snapshot['creationOrder'] ?? []);
            $fresh = $this->service->workerOperation($id);
            if (!empty($fresh['operation']['development']['cancelRequested'])) { throw new RuntimeException('LOCAL_DEMO_CANCELLED_AT_CHECKPOINT'); }
            $shouldInterrupt = $scenario === 'worker-interruption'
                && empty($fresh['operation']['development']['workerInterruptionInjected']);
            $this->service->workerUpdate($id, function (array &$current, array &$state) use ($objectsView, $order, $objectId, $shouldInterrupt): void {
                $current['development']['targetObjects'] = $objectsView;
                $current['development']['dependencyOrder'] = $order;
                $current['development']['lastObjectCheckpoint'] = $objectId;
                $this->service->recordWorkerEvent($state, $current, 'reconstruction_object_written', ['targetId' => $objectId]);
                if ($shouldInterrupt) {
                    $current['development']['workerInterruptionInjected'] = true;
                    $current['development']['workerState'] = 'interrupted_after_durable_object_write';
                    $this->service->recordWorkerEvent($state, $current, 'worker_interrupted');
                }
            });
            if ($shouldInterrupt) { exit(77); }
        };
        $build = (new BuildExecutor($target, $journal, $secrets, $observer))->execute($operation['blueprint'], true);
        $snapshot = $target->snapshot();
        $objectsView = $this->safeObjects($snapshot['objects'] ?? []);
        $this->service->workerUpdate($id, function (array &$current, array &$state) use ($build, $snapshot, $objectsView): void {
            $current['development']['targetObjects'] = $objectsView;
            $current['development']['dependencyOrder'] = array_values($snapshot['creationOrder'] ?? []);
            $current['development']['reconstruction'] = ['state' => $build['state'] ?? 'unknown',
                'savedConfigurationVerified' => ($build['savedConfigurationVerified'] ?? false) === true,
                'operationCount' => count($build['operations'] ?? []), 'targetMode' => $build['targetMode'] ?? 'SIMULATED'];
            $current['development']['apply'] = ['state' => $build['apply']['state'] ?? 'unknown',
                'generation' => $build['apply']['receipt']['generation'] ?? null,
                'reload' => $build['apply']['receipt']['reload'] ?? null];
            $current['development']['replacement']['state'] = ($build['state'] ?? '') === 'configuration_verified' ? 'configuration_verified' : 'partial';
            if (($build['state'] ?? '') !== 'configuration_verified') {
                $current['state'] = !empty($current['development']['cancelRequested']) ? 'cancelled' : 'failed';
                $error = $build['error']['code'] ?? 'RECONSTRUCTION_NOT_VERIFIED';
                $current['errors'][] = ['stage' => $build['error']['stage'] ?? 'reconstruction', 'code' => $this->safeCode($error)];
                $current['milestones'][$current['state']] = gmdate(DATE_ATOM);
                $this->service->recordWorkerEvent($state, $current, $current['state'], ['targetId' => $current['development']['targetId']]);
            } else {
                $current['milestones']['configuration_verified'] = gmdate(DATE_ATOM);
                $this->service->recordWorkerEvent($state, $current, 'configuration_verified', ['targetId' => $current['development']['targetId']]);
            }
        });
        return ($build['state'] ?? '') === 'configuration_verified' ? 'connectivity' : null;
    }

    private function connectivity(array $context): ?string
    {
        $id = $context['operation']['operationId'];
        $this->service->workerUpdate($id, function (array &$operation, array &$state): void {
            if ($operation['development']['cancelRequested']) { $operation['state'] = 'cancelled'; return; }
            $operation['state'] = 'connectivity';
            $this->ensureResource($operation, 'connectivity', false);
            $operation['development']['connectivity'] = ['state' => 'prepared', 'mode' => 'SIMULATED',
                'probe' => 'local target interface model only'];
            $operation['milestones']['connectivity_prepared'] = gmdate(DATE_ATOM);
            $this->service->recordWorkerEvent($state, $operation, 'simulated_connectivity_prepared');
        });
        $fresh = $this->service->workerOperation($id);
        return ($fresh['operation']['state'] ?? '') === 'cancelled' ? null : 'routing';
    }

    private function routing(array $context): ?string
    {
        $id = $context['operation']['operationId'];
        $this->service->workerUpdate($id, function (array &$operation, array &$state): void {
            if ($operation['development']['cancelRequested']) { $operation['state'] = 'cancelled'; return; }
            $operation['state'] = 'routing_prepared';
            $this->ensureResource($operation, 'routing-policy', false);
            if ($operation['development']['scenario'] === 'shared-resource') {
                $this->ensureResource($operation, 'shared-routing-pool', true);
            }
            $expires = $operation['development']['scenario'] === 'expired-readiness' ? time() - 60 : time() + 120;
            $operation['development']['routing'] = ['state' => 'prepared',
                'destination' => $operation['development']['targetId'], 'mode' => 'SIMULATED',
                'policy' => $operation['request']['activationPolicy']];
            $operation['development']['readiness'] = ['state' => 'SIMULATED_ONLY', 'activationAllowed' =>
                $expires > time() && $operation['request']['intent'] === 'recovery'
                    && $operation['request']['activationPolicy'] === 'manual',
                'expiresAt' => gmdate(DATE_ATOM, $expires), 'checks' => [
                    'configuration' => 'verified by BuildExecutor read-back',
                    'apply' => 'simulated target adapter receipt',
                    'connectivity' => 'simulated target interface model',
                    'routing' => 'prepared; traffic not active',
                    'registration' => 'not tested', 'calls' => 'not tested', 'audio' => 'not tested']];
            $operation['milestones']['routing_prepared'] = gmdate(DATE_ATOM);
            $this->service->recordWorkerEvent($state, $operation, 'simulated_routing_prepared', ['targetId' => $operation['development']['targetId']]);
        });
        return null;
    }

    private function seedConflict(SimulatedPbx $target, array $operation): void
    {
        $instruction = null;
        foreach ($operation['blueprint']['operations'] as $candidate) {
            if (($candidate['object']['type'] ?? '') === 'user') { $instruction = $candidate; break; }
        }
        if ($instruction === null) { throw new RuntimeException('LOCAL_DEMO_CONFLICT_FIXTURE_INVALID'); }
        $targetRoot = $this->targetRoot($operation['operationId']);
        $objects = new JsonStateStore($targetRoot . '/objects');
        $objects->update(static function (array &$state) use ($instruction): void {
            $parameters = $instruction['parameters'];
            $parameters['name'] = 'Conflicting foreign object';
            $state['objects'][$instruction['id']] = ['state' => 'complete', 'parameters' => $parameters,
                'owner' => 'synthetic-foreign-owner', 'shared' => true, 'dependsOn' => []];
            $state['creationOrder'][] = $instruction['id'];
        });
    }

    private function seedSharedObject(SimulatedPbx $target, array $operation): void
    {
        $instruction = null;
        foreach ($operation['blueprint']['operations'] as $candidate) {
            if ($candidate['object']['type'] === 'user') { $instruction = $candidate; break; }
        }
        if ($instruction === null) { throw new RuntimeException('LOCAL_DEMO_SHARED_FIXTURE_INVALID'); }
        $targetRoot = $this->targetRoot($operation['operationId']);
        $objects = new JsonStateStore($targetRoot . '/objects');
        $objects->update(static function (array &$state) use ($instruction): void {
            $state['objects'][$instruction['id']] = ['state' => 'complete', 'parameters' => $instruction['parameters'],
                'owner' => 'synthetic-shared-owner', 'shared' => true, 'dependsOn' => []];
            $state['creationOrder'][] = $instruction['id'];
        });
    }

    private function safeObjects(array $objects): array
    {
        $safe = [];
        foreach ($objects as $id => $record) {
            $safe[] = ['id' => $id, 'state' => $record['state'] ?? 'unknown',
                'parameters' => $record['parameters'] ?? [], 'owner' => $record['owner'] ?? 'unknown',
                'shared' => ($record['shared'] ?? false) === true,
                'dependsOn' => is_array($record['dependsOn'] ?? null) ? $record['dependsOn'] : []];
        }
        return $safe;
    }

    private function resource(array $operation, string $kind, bool $shared): array
    {
        return ['id' => $operation['operationId'] . ':' . $kind, 'owner' => $shared ? 'synthetic-shared-owner' : $operation['operationId'],
            'shared' => $shared, 'kind' => $kind, 'state' => 'owned',
            'dependsOn' => array_column($operation['resources'], 'id')];
    }

    private function ensureResource(array &$operation, string $kind, bool $shared): void
    {
        $candidate = $this->resource($operation, $kind, $shared);
        foreach ($operation['resources'] as $existing) {
            if (($existing['id'] ?? '') === $candidate['id']) { return; }
        }
        $operation['resources'][] = $candidate;
    }

    private function targetRoot(string $operationId): string
    {
        return $this->root . '/targets/' . hash('sha256', $operationId);
    }

    private function safeCode(string $code): string
    {
        return preg_match('/^[A-Z0-9_]{3,64}$/D', $code) ? $code : 'LOCAL_DEMO_OPERATION_FAILED';
    }
}