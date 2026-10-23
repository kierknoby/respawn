<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$root = localDemoRoot();
$repository = dirname(__DIR__, 3);
$expected = $repository . '/build/local-demo';
$testParent = $repository . '/build/local-demo-tests';
$temporaryParent = realpath(sys_get_temp_dir());
$canonicalRoot = realpath($root);
$validDefault = $canonicalRoot !== false && $canonicalRoot === realpath($expected);
$validTestRoot = $canonicalRoot !== false && realpath(dirname($canonicalRoot)) === realpath($testParent)
    && preg_match('/^integration-[a-zA-Z0-9_-]+$/D', basename($canonicalRoot));
$validTemporaryTestRoot = $canonicalRoot !== false && dirname($canonicalRoot) === $temporaryParent
    && preg_match('/^respawn-local-demo-integration-[a-zA-Z0-9_-]+$/D', basename($canonicalRoot));
if ((!$validDefault && !$validTestRoot && !$validTemporaryTestRoot)
    || is_link($root) || !is_file($root . '/.respawn-local-demo')
    || trim((string) file_get_contents($root . '/.respawn-local-demo')) !== 'respawn-local-pond-dev-v1') {
    fwrite(STDERR, "Refusing reset: an exact marker-owned Local Pond runtime root is required.\n");
    exit(2);
}
foreach (['pond.pid','dashboard.pid','worker.pid'] as $pidName) {
    $pidPath = $root . '/processes/' . $pidName;
    if (is_file($pidPath)) {
        $pid = (int) file_get_contents($pidPath);
        if ($pid > 0 && is_dir('/proc/' . $pid)) { fwrite(STDERR, "Stop the named demo before reset.\n"); exit(3); }
    }
}
if (($argv[1] ?? '') !== '--confirm=LOCAL-DEMO-RESET') {
    fwrite(STDERR, "Confirm only this named demo: bin/reset.sh\n");
    exit(2);
}

$allowed = ['credentials','pond-state','operator-state','targets','sessions','logs','processes'];
foreach ($allowed as $name) {
    $path = $root . '/' . $name;
    if (file_exists($path)) { removeNamedTree($root, $path); }
}
Respawn\LocalPond\LocalPondSetup::initialise($root, localFixturePath());
echo "Reset only the marker-owned Local Pond runtime; new synthetic credentials were generated.\n";

function removeNamedTree(string $root, string $path): void
{
    $rootReal = realpath($root);
    if ($rootReal === false || is_link($path) || !str_starts_with($path, $rootReal . '/')) {
        throw new RuntimeException('LOCAL_DEMO_RESET_PATH_REJECTED');
    }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            removeNamedTree($rootReal, $path . '/' . $entry);
        }
        if (!rmdir($path)) { throw new RuntimeException('LOCAL_DEMO_RESET_FAILED'); }
        return;
    }
    if (!is_file($path) || !unlink($path)) { throw new RuntimeException('LOCAL_DEMO_RESET_FAILED'); }
}