<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/module/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Respawn\\LocalPond\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) { require_once $path; }
});

function localDemoRoot(): string
{
    $root = getenv('RESPAWN_LOCAL_DEMO_ROOT') ?: dirname(__DIR__, 2) . '/build/local-demo';
    if (!str_starts_with($root, '/')) { throw new RuntimeException('LOCAL_DEMO_ROOT_UNSAFE'); }
    return rtrim($root, '/');
}

function localPondPort(): int
{
    $port = (int) (getenv('RESPAWN_LOCAL_POND_PORT') ?: 8731);
    if ($port < 1024 || $port > 65535) { throw new RuntimeException('LOCAL_DEMO_PORT_INVALID'); }
    return $port;
}

function localDashboardPort(): int
{
    $port = (int) (getenv('RESPAWN_LOCAL_DASHBOARD_PORT') ?: 8732);
    if ($port < 1024 || $port > 65535 || $port === localPondPort()) { throw new RuntimeException('LOCAL_DEMO_PORT_INVALID'); }
    return $port;
}

function localFixturePath(): string { return __DIR__ . '/fixtures/source-pbxs.json'; }