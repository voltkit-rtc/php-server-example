<?php
/** A fake VoltKit controller for the client test: answers the two endpoints the server uses. */
declare(strict_types=1);

header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];
$key = $_SERVER['HTTP_X_API_KEY'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $path === '/v1/rooms/quota-room') {
    http_response_code(402);
    echo json_encode(['error' => 'quota-exhausted', 'message' => 'No minutes left']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/v1/rooms/([^/]+)$#', $path, $m)) {
    http_response_code(201);
    echo json_encode(['roomId' => $m[1], 'hostIdentity' => $body['hostIdentity'] ?? null, 'seenKey' => $key]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/v1/rooms/([^/]+)/tokens$#', $path, $m)) {
    echo json_encode(['token' => 'tok', 'wsUrl' => 'wss://rtc.example/ws', 'identity' => $body['identity'], 'role' => $body['role'], 'roomId' => $m[1], 'expiresAt' => 123]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'not-found', 'message' => 'no such route']);
}
