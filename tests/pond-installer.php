<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$installer = $root . '/pond/install.sh';
if (!is_file($installer)) { throw new RuntimeException('FAIL: standalone installer missing.'); }
$lint = [];
exec('sh -n ' . escapeshellarg($installer), $lint, $lintCode);
if ($lintCode !== 0) { throw new RuntimeException('FAIL: standalone installer shell syntax invalid.'); }
$pipes = [];
$process = proc_open(['sh',$installer,'--preflight'], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
$started = is_resource($process);
if (!$started) { throw new RuntimeException('FAIL: installer preflight could not start.'); }
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$preflightCode = proc_close($process);
if (!in_array($preflightCode,[0,1],true)
    || !str_contains($stdout,'Changes made: no') || !str_contains($stdout,'Dependency php8.4-fpm:')
    || $stderr !== '') {
    throw new RuntimeException('FAIL: installer preflight output/side-effect contract invalid.');
}
echo "PASS: Debian Pond installer syntax and read-only dependency preflight.\n";

$temporary = sys_get_temp_dir() . '/respawn-pond-health-' . bin2hex(random_bytes(6));
$state = $temporary . '/state';
$key = $temporary . '/master.key';
$process = proc_open([PHP_BINARY,$root . '/pond/bin/pond.php','status',$state,$key],
    [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$statusCode = proc_close($process);
if ($statusCode === 0 || file_exists($state) || file_exists($key)) {
    throw new RuntimeException('FAIL: Pond health status created missing storage or key.');
}
echo "PASS: Pond status fails read-only when uninitialised and creates no storage or key.\n";