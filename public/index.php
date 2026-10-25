<?php
/**
 * Entry point. Run with PHP's built-in server:
 *   php -S 0.0.0.0:7000 -t public public/index.php
 * or point Apache/nginx+php-fpm at this file as the front controller.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/app.php';

loadDotEnv(__DIR__ . '/../.env');
try {
    $config = readConfig(getenv());
} catch (InvalidArgumentException $e) {
    http_response_code(500);
    error_log($e->getMessage());
    exit;
}

$rtc = new VoltKitClient($config['voltkit']['baseUrl'], $config['voltkit']['apiKey']);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

[$status, $headers, $body] = handleRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    $_SERVER['HTTP_X_APP_KEY'] ?? null,
    (string) file_get_contents('php://input'),
    $config['appKey'],
    $rtc,
);

http_response_code($status);
header('Content-Type: application/json');
header_remove('X-Powered-By');
foreach ($headers as $name => $value) {
    header("$name: $value");
}
echo json_encode($body, JSON_UNESCAPED_SLASHES);
