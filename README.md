# Token server (PHP)

The one piece of backend a VoltKit app needs, in plain PHP 8.2+ with no Composer packages: it keeps your VoltKit
API secret and gives each user a short-lived room token. It exposes the same `POST /token` API as the other VoltKit token servers, so the
[Flutter](https://github.com/voltkit-rtc/flutter-example) and [Android](https://github.com/voltkit-rtc/android-example)
example apps work against it unchanged.

## Run

```bash
cp .env.example .env            # fill in LAB_KEY (any long random string) and VOLTKIT_RTC_SECRET
php -S 0.0.0.0:7000 public/index.php   # http://localhost:7000
php test/app.test.php           # tests; no VoltKit account needed
```

Needs the `curl` and `json` extensions (on by default in most builds). For real traffic put
[`public/index.php`](public/index.php) behind php-fpm + nginx as the front controller; the built-in server is
for development. `docker build -t voltkit-token-php . && docker run --env-file .env -p 7000:7000 voltkit-token-php`
also works.

## The API

`GET /health` → `{ "status": "ok" }`

`POST /token` with header `X-App-Key: <LAB_KEY>` and body `{ "room": "demo-room", "identity": "asha", "role": "host" }`

```json
{ "wsUrl": "wss://…", "token": "…", "room": "demo-room", "identity": "asha", "role": "host", "expiresAt": 1761040000 }
```

| Field | Rule |
| --- | --- |
| `room` | 3–64 chars of `a-z 0-9 -`, starting with a letter or digit |
| `identity` | 1–64 chars of `A-Z a-z 0-9 . _ -` |
| `role` | `host`, `cohost`, `moderator` or `viewer`; defaults to `host` |

Errors are `{ "error": { "code", "message" } }`:

| Status | Code | When |
| --- | --- | --- |
| 400 | `bad-json`, `bad-room`, `bad-identity`, `bad-role` | Input failed a rule above |
| 401 | `unauthorized` | Missing or wrong `X-App-Key` |
| 404 | `not-found` | Any other route or method |
| 413 | `too-large` | Body over 4 KB |
| 4xx/5xx | VoltKit's own code | VoltKit refused (for example `quota-exhausted` when no minutes are left) |
| 502 | `voltkit-unavailable` | VoltKit did not answer (network error or timeout after retries) |

## The code

| File | Role |
| --- | --- |
| [`src/app.php`](src/app.php) | `handleRequest()`: check the app key (constant time), check the input, ask VoltKit. Pure function, so the tests call it directly. |
| [`src/voltkit.php`](src/voltkit.php) | `VoltKitClient`: `ensureRoom()` is `PUT /v1/rooms/{id}`, `createToken()` is `POST /v1/rooms/{id}/tokens`, both with `X-API-Key`. Retries network errors, 429 and 5xx twice with backoff; never follows redirects (that would leak the key). |
| [`src/config.php`](src/config.php) | Reads `.env` and validates `LAB_KEY`, `VOLTKIT_RTC_URL`, `VOLTKIT_RTC_SECRET`, `PORT`, `HOST`. |
| [`public/index.php`](public/index.php) | Front controller: adapts PHP's request globals to `handleRequest()` and writes the JSON reply. |
| [`test/app.test.php`](test/app.test.php) | Handler tests with a fake VoltKit, plus the real cURL client against [`test/fake_voltkit.php`](test/fake_voltkit.php) on a local port. |

In your own product, replace the `X-App-Key` check with your user login, and decide the `role` from who that user is.
