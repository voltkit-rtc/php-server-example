<?php
/**
 * A minimal VoltKit REST client (cURL, no dependencies).
 *
 * Only the two calls a token server needs:
 *   ensureRoom()  PUT  /v1/rooms/{roomId}         creates the room or returns it (idempotent)
 *   createToken() POST /v1/rooms/{roomId}/tokens  mints a short-lived token for one identity + role
 *
 * Every request carries the tenant secret in X-API-Key; nothing in here is ever
 * reached by an app directly.
 */
declare(strict_types=1);

/** A VoltKit error. `status` is the HTTP status (0 = network), `code` is VoltKit's error code. */
final class VoltKitError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class VoltKitClient
{
    private const MAX_ATTEMPTS = 3; // original try + 2 retries on transient failures

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 8,
    ) {
    }

    /** Create the room, or get it back if it already exists. One round trip either way. */
    public function ensureRoom(string $roomId, string $hostIdentity): array
    {
        return $this->request('PUT', '/v1/rooms/' . rawurlencode($roomId), ['hostIdentity' => $hostIdentity]);
    }

    /** Mint a room token. The role is decided here, on the server, and signed into the token. */
    public function createToken(string $roomId, string $identity, string $role): array
    {
        return $this->request('POST', '/v1/rooms/' . rawurlencode($roomId) . '/tokens', [
            'identity' => $identity,
            'role' => $role,
        ]);
    }

    /** Send one JSON request, retrying on network errors, 429 and 5xx. Returns the decoded body. */
    private function request(string $method, string $path, array $body): array
    {
        $last = null;
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                usleep((200_000 * 2 ** ($attempt - 1)) + random_int(0, 100_000)); // backoff with jitter
            }
            try {
                return $this->once($method, $path, $body);
            } catch (VoltKitError $e) {
                $retryable = $e->status === 0 || $e->status === 429 || $e->status >= 500;
                if (!$retryable || $e->errorCode === 'billing-unavailable') {
                    throw $e;
                }
                $last = $e;
            }
        }
        throw $last;
    }

    private function once(string $method, string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // never hand the key to a redirect target
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $text = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($text === false) {
            throw new VoltKitError('network', 0, "$method $path: " . ($curlError ?: 'request failed'));
        }
        if ($status >= 300 && $status < 400) {
            throw new VoltKitError('redirected', $status, "$method $path: redirected; point VOLTKIT_RTC_URL at the controller itself.");
        }

        $decoded = json_decode((string) $text, true);
        if ($status < 200 || $status >= 300) {
            // VoltKit answers { error, message } (or nested { error: { code, message } }).
            $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            $code = is_array($err) ? ($err['code'] ?? null) : $err;
            $message = is_array($err) ? ($err['message'] ?? null) : ($decoded['message'] ?? null);
            throw new VoltKitError(
                is_string($code) && $code !== '' ? $code : "http-$status",
                $status,
                "$method $path: " . (is_string($message) && $message !== '' ? $message : substr((string) $text, 0, 300)),
            );
        }
        if (!is_array($decoded)) {
            throw new VoltKitError("http-$status", $status, "$method $path: the reply is not JSON.");
        }
        return $decoded;
    }
}
