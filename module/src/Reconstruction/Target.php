<?php
declare(strict_types=1);

namespace Respawn\Reconstruction;

interface Target
{
    public function identity(): string;
    public function read(string $number): ?array;
    public function create(array $parameters): void;
    public function prerequisites(array $operations): void;
}