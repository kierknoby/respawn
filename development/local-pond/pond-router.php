<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($remote !== '127.0.0.1' || !preg_match('/^127\.0\.0\.1:[0-9]{1,5}$/D', $host)) {
    http_response_code(403);
    echo json_encode(['error' => 'LOOPBACK_ONLY'], JSON_UNESCAPED_SLASHES);
    return;
}
if ($path === '/health' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(['service' => 'Local Pond', 'environment' => 'DEVELOPMENT ONLY', 'loopback' => true]);
    return;
}
if (!in_array($path, ['/rpc', '/alternate/rpc'], true) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo json_encode(['error' => 'NOT_FOUND']);
    return;
}
$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length < 2 || $length > 65536) {
    http_response_code(413);
    echo json_encode(['error' => 'REQUEST_TOO_LARGE']);
    return;
}
$body = file_get_contents('php://input', false, null, 0, 65537);
try {
    if (!is_string($body) || strlen($body) > 65536) { throw new RuntimeException('PROVIDER_REQUEST_INVALID'); }
    $envelope = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($envelope)) { throw new RuntimeException('PROVIDER_REQUEST_INVALID'); }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $matches)) { throw new RuntimeException('LOCAL_POND_AUTH_FAILED'); }
    $endpoint = 'http://' . $host . $path;
    $result = (new Respawn\LocalPond\LocalPondService(localDemoRoot()))->dispatch($envelope, $matches[1], $endpoint);
    $response = ['schema' => 'respawn.provider-rpc.v2', 'connectionId' => $envelope['connectionId'],
        'method' => $envelope['method'], 'pbxId' => $envelope['pbxId'], 'ok' => true,
        'observedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 120), 'result' => $result];
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $code = preg_match('/^[A-Z0-9_]{3,64}$/D', $exception->getMessage()) ? $exception->getMessage() : 'LOCAL_POND_REQUEST_FAILED';
    error_log('Local Pond diagnostic ' . json_encode(['code' => $code, 'method' => $envelope['method'] ?? 'unknown']));
    http_response_code($code === 'LOCAL_POND_AUTH_FAILED' ? 401 : 409);
    $request = is_array($envelope ?? null) ? $envelope : [];
    echo json_encode(['schema' => 'respawn.provider-rpc.v2', 'connectionId' => $request['connectionId'] ?? '',
        'method' => $request['method'] ?? '', 'pbxId' => $request['pbxId'] ?? '', 'ok' => false,
        'observedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 30),
        'error' => ['code' => $code]], JSON_UNESCAPED_SLASHES);
}