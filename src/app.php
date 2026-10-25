<?php
/**
 * The whole token server. Your app asks for a room token, this server checks the app is
 * yours and asks VoltKit for one. The VoltKit API secret never leaves the server; the app
 * only ever holds a short-lived room token.
 *
 * handleRequest() is pure (request in, [status, headers, body] out) so it can be tested
 * without a web server; public/index.php wires it to PHP's SAPI.
 */
declare(strict_types=1);

require_once __DIR__ . '/voltkit.php';

const ROOM_RE = '/^[a-z0-9][a-z0-9-]{2,63}$/';
const IDENTITY_RE = '/^[A-Za-z0-9._-]{1,64}$/';
const ROLES = ['host', 'cohost', 'moderator', 'viewer'];
const MAX_BODY_BYTES = 4096;

/**
 * @param string      $method   HTTP method
 * @param string      $path     Request path without query string
 * @param ?string     $appKeyIn The X-App-Key header, if sent
 * @param string      $rawBody  Raw request body
 * @param string      $appKey   The expected app key (LAB_KEY)
 * @param object      $rtc      Anything with ensureRoom() and createToken() (VoltKitClient or a fake)
 * @return array{0:int,1:array<string,string>,2:array} [status, extra headers, JSON body]
 */
function handleRequest(string $method, string $path, ?string $appKeyIn, string $rawBody, string $appKey, object $rtc): array
{
    if ($method === 'GET' && $path === '/health') {
        return [200, [], ['status' => 'ok']];
    }
    if (!($method === 'POST' && $path === '/token')) {
        return fail(404, 'not-found', 'Use POST /token.');
    }

    // 1. Check the caller is your app. In a real product this is your own user login.
    if (!sameKey($appKeyIn, $appKey)) {
        return fail(401, 'unauthorized', 'Wrong app key.');
    }

    // 2. Check the input.
    if (strlen($rawBody) > MAX_BODY_BYTES) {
        return fail(413, 'too-large', 'Body too large.');
    }
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        return fail(400, 'bad-json', 'Send a JSON body.');
    }
    $room = $body['room'] ?? '';
    $identity = $body['identity'] ?? '';
    $role = $body['role'] ?? 'host';
    if (!is_string($room) || !preg_match(ROOM_RE, $room)) {
        return fail(400, 'bad-room', 'room: 3–64 of a-z 0-9 -');
    }
    if (!is_string($identity) || !preg_match(IDENTITY_RE, $identity)) {
        return fail(400, 'bad-identity', 'identity: 1–64 of A-Z a-z 0-9 . _ -');
    }
    if (!is_string($role) || !in_array($role, ROLES, true)) {
        return fail(400, 'bad-role', 'role: ' . implode(', ', ROLES));
    }

    // 3. Make sure the room exists, then mint a token for this person and role.
    try {
        $rtc->ensureRoom($room, $identity);
        $t = $rtc->createToken($room, $identity, $role);
        return [200, ['Cache-Control' => 'no-store'], [
            'wsUrl' => $t['wsUrl'] ?? null,
            'token' => $t['token'] ?? null,
            'room' => $room,
            'identity' => $t['identity'] ?? $identity,
            'role' => $t['role'] ?? $role,
            'expiresAt' => $t['expiresAt'] ?? null,
        ]];
    } catch (VoltKitError $e) {
        // VoltKit's own errors (e.g. no minutes left) keep their status and code.
        if ($e->status >= 400) {
            return fail($e->status, $e->errorCode, $e->getMessage());
        }
        return fail(502, 'voltkit-unavailable', 'VoltKit did not answer.');
    } catch (Throwable $e) {
        error_log('Request failed: ' . get_class($e));
        return fail(500, 'internal', 'Unexpected server error.');
    }
}

/** The error shape every failure uses: { "error": { "code", "message" } }. */
function fail(int $status, string $code, string $message): array
{
    return [$status, [], ['error' => ['code' => $code, 'message' => $message]]];
}

/** Constant-time comparison, so the key cannot be guessed from response times. */
function sameKey(?string $given, string $expected): bool
{
    return is_string($given) && hash_equals($expected, $given);
}
