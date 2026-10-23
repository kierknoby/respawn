<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

use Respawn\Storage\JsonStateStore;
use RuntimeException;

final class Executor
{
    public function __construct(private Target $target, private JsonStateStore $store)
    {
    }

    public function execute(array $blueprint, bool $authorised = false): array
    {
        if (!$authorised) {
            throw new RuntimeException('Explicit target reconstruction authorisation is required.');
        }
        Blueprint::verifyHash($blueprint);
        $operations = Blueprint::ordered($blueprint);
        $this->target->prerequisites($operations);
        $scope = $this->target->identity() . ':' . $blueprint['contentHash'];
        return $this->store->update(function (array &$state) use ($scope, $operations): array {
            $checkpoint = &$state['builds'][$scope];
            $checkpoint ??= ['state' => 'running', 'verified' => [], 'events' => []];
            foreach ($operations as $operation) {
                try {
                    $current = $this->target->read($operation['parameters']['number']);
                    if ($current !== null && $current !== $operation['parameters']) {
                        throw new RuntimeException('Target object conflicts with blueprint.');
                    }
                } catch (\Throwable $exception) {
                    $checkpoint['state'] = 'failed';
                    $checkpoint['failedOperation'] = $operation['id'];
                    $checkpoint['error'] = 'Preflight failed before mutation: reconcile target conflicts or unsupported state.';
                    return $checkpoint;
                }
            }
            foreach ($operations as $operation) {
                $parameters = $operation['parameters'];
                try {
                    $current = $this->target->read($parameters['number']);
                    if ($current !== null && $current !== $parameters) {
                        throw new RuntimeException('Target object conflicts with blueprint; no overwrite is permitted.');
                    }
                    if ($current === null) {
                        $this->target->create($parameters);
                    }
                    if ($this->target->read($parameters['number']) !== $parameters) {
                        throw new RuntimeException('Read-back verification failed.');
                    }
                    $checkpoint['verified'][$operation['id']] = hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
                    $checkpoint['events'][] = ['operation' => $operation['id'], 'state' => 'verified', 'at' => gmdate(DATE_ATOM)];
                } catch (\Throwable $exception) {
                    $checkpoint['state'] = 'failed';
                    $checkpoint['failedOperation'] = $operation['id'];
                    $checkpoint['error'] = 'Reconstruction stopped. Check target compatibility, prerequisites, conflicts and read-back; retry this same blueprint after correction.';
                    return $checkpoint;
                }
            }
            $checkpoint['state'] = 'configuration_verified';
            unset($checkpoint['error'], $checkpoint['failedOperation']);
            $checkpoint['callReadinessVerified'] = false;
            return $checkpoint;
        });
    }
}