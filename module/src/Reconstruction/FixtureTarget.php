<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

use RuntimeException;

final class FixtureTarget implements Target
{
    public array $groups = [];
    public array $creationOrder = [];
    public ?string $failNumber = null;

    public function __construct(private string $id = 'fixture-target', private array $members = ['1001', '1002'])
    {
    }

    public function identity(): string { return $this->id; }
    public function read(string $number): ?array { return $this->groups[$number] ?? null; }
    public function prerequisites(array $operations): void
    {
        foreach ($operations as $operation) {
            if (array_diff($operation['parameters']['members'], $this->members)) {
                throw new RuntimeException('Member extensions must exist before ring-group reconstruction.');
            }
        }
    }
    public function create(array $parameters): void
    {
        if ($parameters['number'] === $this->failNumber) {
            throw new RuntimeException('Injected fixture failure.');
        }
        $destination = $parameters['destination'];
        if ($destination !== 'hangup' && !isset($this->groups[$destination])) {
            throw new RuntimeException('Destination dependency is absent.');
        }
        $this->groups[$parameters['number']] = $parameters;
        $this->creationOrder[] = $parameters['number'];
    }
}