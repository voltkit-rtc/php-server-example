<?php
/**
 * Settings from the environment (.env). The VoltKit secret stays on this server.
 *
 * Returns ['appKey', 'port', 'host', 'voltkit' => ['baseUrl', 'apiKey']] or throws
 * InvalidArgumentException naming the variable to fix.
 */
declare(strict_types=1);

/** Load KEY=VALUE lines from a .env file into $_ENV, without overriding real env vars. */
function loadDotEnv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'"); // strip optional quotes
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/** @param array<string, mixed> $env Usually getenv(); injectable for tests. */
function readConfig(array $env): array
{
    $appKey = $env['LAB_KEY'] ?? '';
    $baseUrl = $env['VOLTKIT_RTC_URL'] ?? '';
    $apiKey = $env['VOLTKIT_RTC_SECRET'] ?? '';

    if (strlen((string) $appKey) < 16) {
        throw new InvalidArgumentException('Set LAB_KEY: the key your app sends (16+ characters).');
    }
    if (!str_starts_with((string) $baseUrl, 'https://')) {
        throw new InvalidArgumentException('Set VOLTKIT_RTC_URL, e.g. https://rtc.voltkit.in');
    }
    if ($apiKey === '') {
        throw new InvalidArgumentException('Set VOLTKIT_RTC_SECRET: your VoltKit API secret.');
    }
    $port = (int) ($env['PORT'] ?? 7000);
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('PORT must be 1–65535.');
    }

    return [
        'appKey' => (string) $appKey,
        'port' => $port,
        'host' => (string) ($env['HOST'] ?? '0.0.0.0'),
        'voltkit' => ['baseUrl' => rtrim((string) $baseUrl, '/'), 'apiKey' => (string) $apiKey],
    ];
}
