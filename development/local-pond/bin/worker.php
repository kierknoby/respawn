<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
$service = new Respawn\LocalPond\LocalPondService(localDemoRoot());
$service->recoverInterruptedJobs();
$worker = new Respawn\LocalPond\LocalPondWorker($service, localDemoRoot());
if (($argv[1] ?? '') === '--once') {
    $worker->runOne();
    exit(0);
}
while (true) {
    if (!$worker->runOne()) { usleep(100000); }
}