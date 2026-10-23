<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/assets/app.js','/assets/style.css'], true)) { return false; }
if ($path === '/' || $path === '/index.php' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/public/index.php';
    return true;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Local demonstration route not found.';