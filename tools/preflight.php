<?php
declare(strict_types=1);
require __DIR__ . '/../module/bootstrap.php';

$options = getopt('', ['role:', 'fwconsole:', 'cron:', 'state:', 'key:', 'token:', 'bundle:']);
$role = $options['role'] ?? '';
$checks = [];
$checks['role'] = in_array($role, ['source','pond','recovery'], true);
$checks['php_cli_8_2_or_newer'] = PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80200;
foreach (['json','hash','filter'] as $extension) { $checks['extension_' . $extension] = extension_loaded($extension); }
if (in_array($role, ['source','recovery'], true)) { $checks['extension_curl'] = extension_loaded('curl'); }
if ($role === 'pond') {
    $checks['pdo_sqlite'] = class_exists(PDO::class) && in_array('sqlite', PDO::getAvailableDrivers(), true);
    $checks['openssl_aes_256_gcm'] = extension_loaded('openssl') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
}
$checks['durable_file_functions'] = function_exists('fsync') && function_exists('flock');
$safePath = static function (string $path, bool $directory): bool {
    if (realpath($path) !== $path || is_link($path) || ($directory ? !is_dir($path) : !is_file($path))
        || (fileperms($path) & 0077) !== 0 || !is_readable($path)) { return false; }
    for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
        $permissions = fileperms($parent);
        if (($permissions & 0022) !== 0 && !(($permissions & 01000) !== 0 && fileowner($parent) === 0)) { return false; }
    }
    return true;
};
if ($role === 'source') {
    $console = $options['fwconsole'] ?? '/usr/sbin/fwconsole';
    $checks['fwconsole_executable'] = is_file($console) && is_executable($console);
    $checks['freepbx_configuration_readable'] = is_readable('/etc/freepbx.conf');
    $checks['pdo_mysql'] = class_exists(PDO::class) && in_array('mysql', PDO::getAvailableDrivers(), true);
    $account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
    $checks['run_as_asterisk'] = ($account['name'] ?? '') === 'asterisk';
    $cronPath = $options['cron'] ?? '/etc/cron.d/respawn-protection';
    $cron = is_file($cronPath) && is_readable($cronPath) ? file_get_contents($cronPath) : '';
    $checks['cron_format'] = preg_match('/^\* \* \* \* \* asterisk ' . preg_quote($console, '/') . ' respawn protection-reconcile\n\s*$/D', $cron) === 1;
    $checks['cron_owner_and_mode'] = is_file($cronPath) && !is_link($cronPath) && fileowner($cronPath) === 0
        && (fileperms($cronPath) & 0022) === 0;
    $checks['cron_daemon_observed'] = is_dir('/proc') && array_filter(glob('/proc/[0-9]*/comm') ?: [],
        static fn (string $path): bool => is_readable($path) && in_array(trim((string) @file_get_contents($path)), ['cron','crond'], true)) !== [];
}
foreach (['key','token'] as $name) {
    if (isset($options[$name])) {
        $checks[$name . '_protected'] = $safePath($options[$name], false);
        $checks[$name . '_length'] = is_file($options[$name]) && ($name === 'key'
            ? filesize($options[$name]) === 32 : filesize($options[$name]) >= 32 && filesize($options[$name]) <= 4096);
    }
}
if (isset($options['state'])) {
    $checks['state_private_and_writable'] = $safePath($options['state'], true) && is_writable($options['state']);
    $checks['state_space_available'] = is_dir($options['state']) && disk_free_space($options['state']) > 104857600;
}
if (isset($options['bundle'])) {
    $checks['bundle_private'] = $safePath($options['bundle'], true);
    try {
        $directory = $options['bundle'];
        if (!$checks['bundle_private'] || !$safePath($directory . '/blueprint.json', false)
            || !$safePath($directory . '/secrets.json', false)) { throw new RuntimeException('BUNDLE_UNSAFE'); }
        $blueprint = json_decode(file_get_contents($directory . '/blueprint.json'), true, 64, JSON_THROW_ON_ERROR);
        \Respawn\Reconstruction\Blueprint::verifyHash($blueprint);
        $resolver = new \Respawn\Reconstruction\FileSecrets($directory . '/secrets.json');
        foreach (\Respawn\Reconstruction\BuildSpec::plan($blueprint) as $operation) {
            foreach ($operation['secretRefs'] as $reference) { $resolver->resolve($reference, $blueprint['sourcePbxId']); }
        }
        $checks['bundle_integrity_and_credentials'] = true;
    } catch (Throwable) { $checks['bundle_integrity_and_credentials'] = false; }
}
echo json_encode(['state'=>!in_array(false, $checks, true) ? 'prerequisites_available' : 'prerequisites_missing',
    'role'=>$role, 'phpVersion'=>PHP_VERSION, 'phpBinary'=>PHP_BINARY, 'phpIni'=>php_ini_loaded_file() ?: null,
    'opensslVersion'=>defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
    'curlVersion'=>function_exists('curl_version') ? curl_version()['version'] : null,
    'sqliteVersion'=>class_exists(SQLite3::class) ? SQLite3::version()['versionString'] : null,
    'checks'=>$checks, 'hostWrites'=>false, 'networkRequests'=>false,
    'schedulerExecutionProven'=>false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
exit(in_array(false, $checks, true) ? 1 : 0);