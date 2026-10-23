<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use Respawn\Domain\FirewallIntent;
use Respawn\Reconstruction\Blueprint;
use Respawn\Reconstruction\Executor;
use Respawn\Reconstruction\FixtureTarget;
use Respawn\Storage\JsonStateStore;

$count = 0;
function removeTestTree(string $path): void {
    if (!is_dir($path)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($path);
}
function check(bool $condition, string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $count++;
    echo 'PASS: ' . $label . "\n";
}
function rejects(callable $action, string $label): void {
    try { $action(); } catch (Throwable $exception) { check(true, $label); return; }
    check(false, $label);
}
$directory = sys_get_temp_dir() . '/respawn-tests-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $first = FirewallIntent::create('pbx-alpha', true, ['192.0.2.2', '192.0.2.1/32', '192.0.2.2/32'], 'first');
    $second = FirewallIntent::create('pbx-alpha', true, ['192.0.2.1', '192.0.2.2'], 'second');
    check($first['contentHash'] === $second['contentHash'], 'firewall canonicalisation excludes timestamp/order/duplicates');
    foreach (['192.0.2.0/24', '::1', 'x; rm -rf /', ' 192.0.2.1'] as $bad) {
        rejects(fn () => FirewallIntent::create('pbx-alpha', true, [$bad], ''), 'reject unsupported source ' . $bad);
    }
    $groups = [
        ['number' => '600', 'description' => 'Primary', 'strategy' => 'ringall', 'timeout' => 20, 'members' => ['1001'], 'destination' => '601'],
        ['number' => '601', 'description' => 'Fallback', 'strategy' => 'ringall', 'timeout' => 15, 'members' => ['1002'], 'destination' => 'hangup'],
    ];
    $blueprint = Blueprint::create('pbx-alpha', $groups);
    check(array_column(Blueprint::ordered($blueprint), 'id') === ['ringgroup:601', 'ringgroup:600'], 'dependency order overrides source order');
    $target = new FixtureTarget();
    $executor = new Executor($target, new JsonStateStore($directory));
    rejects(fn () => $executor->execute($blueprint), 'execution requires explicit authorisation');
    $target->failNumber = '600';
    check($executor->execute($blueprint, true)['state'] === 'failed', 'partial reconstruction reports failure');
    check($target->creationOrder === ['601'], 'dependency completed before failure');
    $target->failNumber = null;
    $result = $executor->execute($blueprint, true);
    check($result['state'] === 'configuration_verified' && !$result['callReadinessVerified'], 'retry reconstructs and verifies configuration, not live calls');
    $executor->execute($blueprint, true);
    check($target->creationOrder === ['601', '600'], 'retry and lost-response recovery create no duplicates');
    $target->groups['600']['description'] = 'Conflicting object';
    check($executor->execute($blueprint, true)['state'] === 'failed', 'conflicting target is never overwritten');
    $bad = $groups;
    $bad[1]['destination'] = '600';
    rejects(fn () => Blueprint::create('pbx-alpha', $bad), 'cyclic dependency rejected');
    $bad = $groups;
    $bad[0]['destination'] = '999';
    rejects(fn () => Blueprint::create('pbx-alpha', $bad), 'missing dependency rejected');
    $bad = $groups;
    $bad[0]['description'] = '$(curl evil)';
    rejects(fn () => Blueprint::create('pbx-alpha', $bad), 'shell/configuration input rejected');
    $tampered = $blueprint;
    $tampered['sourcePbxId'] = 'spoofed';
    rejects(fn () => Blueprint::verifyHash($tampered), 'modified blueprint hash rejected');
    require __DIR__ . '/ringgroups.php';
    require __DIR__ . '/provider.php';
    $workers = [];
    foreach (range(1, 4) as $worker) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/storage-worker.php', $directory], [], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Could not start storage worker.'); }
        $workers[] = $process;
    }
    foreach ($workers as $process) { check(proc_close($process) === 0, 'storage worker completed'); }
    $counter = (new JsonStateStore($directory))->update(static fn (array &$state): int => $state['counter']);
    check($counter === 60, 'four concurrent processes lose no updates');
    $configuration = ['schema' => 'respawn.provider-config.v1', 'adapterId' => 'example',
        'endpoint' => 'https://provider.example.test/api', 'credentialRef' => '/etc/respawn/provider.token',
        'sourcePbxId' => 'pbx-alpha', 'allowedHosts' => ['provider.example.test']];
    check(Respawn\Provider\Configuration::validate($configuration) === $configuration, 'explicit allowlisted HTTPS configuration accepted without network access');
    rejects(fn () => Respawn\Provider\Configuration::validate($configuration + ['token' => 'synthetic-secret-do-not-store']), 'embedded credential field rejected');
    rejects(fn () => Respawn\Provider\Configuration::validate(array_replace($configuration, ['credentialRef' => '/etc/../secret'])), 'unsafe credential path rejected');
    foreach (['http://provider.example.test', 'https://secret@provider.example.test', 'https://evil.example.test', 'https://provider.example.test/#secret'] as $endpoint) {
        rejects(fn () => Respawn\Provider\Configuration::validate(array_replace($configuration, ['endpoint' => $endpoint])), 'unsafe provider endpoint rejected');
    }
    require __DIR__ . '/https-provider.php';
    require __DIR__ . '/application.php';
    $oversized = $blueprint;
    $oversized['operations'] = array_fill(0, 101, $blueprint['operations'][0]);
    rejects(fn () => Blueprint::ordered($oversized), 'oversized reconstruction rejected before mutation');
    file_put_contents($directory . '/state.json', '{bad json');
    rejects(fn () => (new JsonStateStore($directory))->update(fn (array &$state) => null), 'corrupt state fails without overwrite');
    check(file_get_contents($directory . '/state.json') === '{bad json', 'corrupt evidence retained');
    echo "{$count} checks passed; fixture/contract evidence only.\n";
} finally {
    removeTestTree($directory);
}