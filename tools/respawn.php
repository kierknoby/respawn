<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

use Respawn\Application;
use Respawn\Firewall\FixtureFirewallSource;
use Respawn\Storage\JsonStateStore;

try {
    if (getenv('RESPAWN_FIXTURE_MODE') !== '1') {
        throw new RuntimeException('Standalone harness requires RESPAWN_FIXTURE_MODE=1; use fwconsole respawn on FreePBX.');
    }
    $command = $argv[1] ?? 'status';
    $arguments = ['area' => $argv[2] ?? 'blueprint'];
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--force') { $arguments['force'] = true; continue; }
        if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$key, $value] = explode('=', substr($argument, 2), 2);
            $arguments[$key] = $value;
        }
    }
    $settings = ['mode' => 'mock', 'identity' => getenv('RESPAWN_PBX_ID') ?: 'pbx-alpha',
        'intent' => getenv('RESPAWN_INTENT') ?: 'rehearsal', 'activationPolicy' => getenv('RESPAWN_ACTIVATION') ?: 'none'];
    $directory = getenv('RESPAWN_STATE_DIR');
    if (!$directory) { throw new RuntimeException('Set RESPAWN_STATE_DIR to a private disposable harness directory.'); }
    $application = new Application($settings, new JsonStateStore($directory), new FixtureFirewallSource(__DIR__ . '/../tests/fixtures/firewall.json'));
    echo json_encode($application->run($command, $arguments), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Respawn: ' . $exception->getMessage() . "\n");
    exit(1);
}