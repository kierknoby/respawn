<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';
$store = new Respawn\Storage\JsonStateStore($argv[1]);
for ($iteration = 0; $iteration < 15; $iteration++) {
    $store->update(static function (array &$state): void { $state['counter'] = ($state['counter'] ?? 0) + 1; });
}