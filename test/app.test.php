<?php
/**
 * Tests for the PHP token server. No framework, no network to VoltKit:
 *   php test/app.test.php
 *
 * The handler is tested with a fake VoltKit; the real cURL client is tested against a
 * tiny fake VoltKit served by PHP's built-in web server on localhost.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/app.php';

const KEY = 'example-app-key-0123456789';
$failures = 0;

function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  ok  ' : '  FAIL') . "  $what\n";
    if (!$ok) {
        $failures++;
    }
}

/** A VoltKit stand-in that records calls. */
final class FakeRtc
{
    public array $calls = [];
    public ?VoltKitError $throwOnEnsure = null;

    public function ensureRoom(string $roomId, string $hostIdentity): array
    {
        $this->calls[] = ['ensureRoom', $roomId, $hostIdentity];
        if ($this->throwOnEnsure) {
            throw $this->throwOnEnsure;
        }
        return ['roomId' => $roomId, 'hostToken' => 'never-sent'];
    }

    public function createToken(string $roomId, string $identity, string $role): array
    {
        $this->calls[] = ['createToken', $roomId, $identity, $role];
        return ['token' => 'room-token', 'wsUrl' => 'wss://rtc.example/ws', 'identity' => $identity, 'role' => $role, 'expiresAt' => 123];
    }
}

function post(FakeRtc $rtc, ?array $body, ?string $key = KEY, ?string $raw = null): array
{
    [$status, , $json] = handleRequest('POST', '/token', $key, $raw ?? json_encode($body), KEY, $rtc);
    return [$status, $json];
}

echo "POST /token ensures the room and returns a room token\n";
$rtc = new FakeRtc();
[$status, $json] = post($rtc, ['room' => 'demo-room', 'identity' => 'asha', 'role' => 'viewer']);
check($status === 200, 'status 200');
check($json === ['wsUrl' => 'wss://rtc.example/ws', 'token' => 'room-token', 'room' => 'demo-room', 'identity' => 'asha', 'role' => 'viewer', 'expiresAt' => 123], 'body shape');
check($rtc->calls === [['ensureRoom', 'demo-room', 'asha'], ['createToken', 'demo-room', 'asha', 'viewer']], 'VoltKit called in order');
check(post($rtc, ['room' => 'demo-room', 'identity' => 'bo'])[1]['role'] === 'host', 'role defaults to host');

echo "the app key is required\n";
$rtc = new FakeRtc();
check(post($rtc, ['room' => 'demo-room', 'identity' => 'a'], null)[0] === 401, 'missing key');
check(post($rtc, ['room' => 'demo-room', 'identity' => 'a'], 'wrong-key')[0] === 401, 'wrong key');
check($rtc->calls === [], 'VoltKit not called');

echo "bad input is refused before VoltKit is called\n";
$rtc = new FakeRtc();
foreach ([['identity' => 'a'], ['room' => 'X', 'identity' => 'a'], ['room' => 'demo-room', 'identity' => 'has space'], ['room' => 'demo-room', 'identity' => 'a', 'role' => 'owner']] as $body) {
    check(post($rtc, $body)[0] === 400, json_encode($body));
}
check(post($rtc, null, KEY, '{bad json')[0] === 400, 'bad json');
check(post($rtc, null, KEY, json_encode(['room' => str_repeat('x', 5000)]))[0] === 413, 'too large');
check($rtc->calls === [], 'VoltKit not called');

echo "VoltKit errors keep their status and code; other routes are 404\n";
$rtc = new FakeRtc();
$rtc->throwOnEnsure = new VoltKitError('quota-exhausted', 402, 'No minutes left');
[$status, $json] = post($rtc, ['room' => 'demo-room', 'identity' => 'a']);
check($status === 402 && $json['error']['code'] === 'quota-exhausted', 'VoltKit 402 passed through');
$rtc->throwOnEnsure = new VoltKitError('network', 0, 'timed out');
check(post($rtc, ['room' => 'demo-room', 'identity' => 'a'])[0] === 502, 'network error is 502');
check(handleRequest('GET', '/health', null, '', KEY, $rtc)[0] === 200, 'GET /health');
check(handleRequest('GET', '/voltkit/v1/lab/rooms', null, '', KEY, $rtc)[0] === 404, 'unknown route');

echo "configuration\n";
$env = ['LAB_KEY' => KEY, 'VOLTKIT_RTC_URL' => 'https://rtc.example', 'VOLTKIT_RTC_SECRET' => 'secret'];
check(readConfig($env)['port'] === 7000, 'default port');
foreach (['LAB_KEY' => 'short', 'VOLTKIT_RTC_URL' => 'http://rtc.example', 'VOLTKIT_RTC_SECRET' => ''] as $k => $v) {
    try {
        readConfig([...$env, $k => $v]);
        check(false, "$k rejected");
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), $k), "$k rejected");
    }
}

echo "the cURL client talks to VoltKit\n";
// A fake VoltKit on a free local port, served by PHP's built-in server.
$port = 17000 + random_int(0, 999);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", __DIR__ . '/fake_voltkit.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}
try {
    $client = new VoltKitClient("http://127.0.0.1:$port", 'secret-key', 3);
    $room = $client->ensureRoom('demo-room', 'asha');
    check(($room['roomId'] ?? null) === 'demo-room' && ($room['seenKey'] ?? null) === 'secret-key', 'PUT /v1/rooms/{id} with X-API-Key');
    $token = $client->createToken('demo-room', 'asha', 'viewer');
    check(($token['token'] ?? null) === 'tok' && ($token['role'] ?? null) === 'viewer', 'POST /v1/rooms/{id}/tokens');
    try {
        $client->ensureRoom('quota-room', 'asha');
        check(false, 'error reply raises VoltKitError');
    } catch (VoltKitError $e) {
        check($e->status === 402 && $e->errorCode === 'quota-exhausted', 'error reply raises VoltKitError with code and status');
    }
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
