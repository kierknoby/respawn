<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/module/bootstrap.php';
require dirname(__DIR__) . '/Pond.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $localTest = getenv('RESPAWN_POND_LOOPBACK_TEST') === '1'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
    if (!$localTest && ($_SERVER['HTTPS'] ?? '') !== 'on') { throw new RuntimeException('POND_TLS_REQUIRED'); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('POND_REQUEST_INVALID');
    }
    $authorisation = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-zA-Z0-9._~-]{32,256})$/D', $authorisation, $matches)) { throw new RuntimeException('POND_UNAUTHORISED'); }
    $input = file_get_contents('php://input', false, null, 0, 65537);
    if ($input === false || strlen($input) > 65536) { throw new RuntimeException('POND_REQUEST_TOO_LARGE'); }
    $request = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($request)) { throw new RuntimeException('POND_REQUEST_INVALID'); }
    $pond = new \Respawn\Pond\Pond(getenv('RESPAWN_POND_STATE') ?: '', getenv('RESPAWN_POND_KEY') ?: '');
    $result = $pond->dispatch($matches[1], $request);
    echo json_encode(['schema'=>'respawn.provider-rpc.v2', 'connectionId'=>$request['connectionId'],
        'pbxId'=>$request['pbxId'], 'method'=>$request['method'], 'ok'=>true,
        'observedAt'=>gmdate(DATE_ATOM), 'expiresAt'=>gmdate(DATE_ATOM, time()+60), 'result'=>$result], JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'POND_REQUEST_REJECTED'], JSON_THROW_ON_ERROR);
}