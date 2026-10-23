<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Application;
use Respawn\Reconstruction\Executor;
use Respawn\Reconstruction\FixtureTarget;
use Respawn\Storage\JsonStateStore;

$path = sys_get_temp_dir() . '/respawn-preflight-' . bin2hex(random_bytes(6));
$target = new FixtureTarget();
$document = Application::fixtureBlueprint('pbx-alpha');
$target->groups['600'] = $document['operations'][0]['parameters'];
$target->groups['600']['description'] = 'Foreign configuration';
try {
    $result = (new Executor($target, new JsonStateStore($path)))->execute($document, true);
    if ($target->creationOrder !== []) {
        throw new RuntimeException('FAIL: later target conflict allowed earlier mutation.');
    }
    if ($result['state'] !== 'failed') { throw new RuntimeException('Conflict not reported.'); }
    echo "PASS: complete target conflict preflight prevents all mutations.\n";
} finally {
    foreach (glob($path . '/*') as $file) { unlink($file); }
    rmdir($path);
}