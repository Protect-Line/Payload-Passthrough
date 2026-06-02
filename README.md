# Payload Passthrough

Small PHP webhook bridge: receive a POST (or other configured method) on your server and forward the **same raw body** and selected headers to a locked-down API endpoint.

Use this when the upstream API only accepts calls from specific domains or IPs, but your integration runs elsewhere. Point the third-party webhook at `webhook.php` on a host that is allowed, and set `target_url` in `config.php` to the real handler.

No framework required. Uses PHP cURL.

## Setup

1. Copy `config.example.php` to `config.php`.
2. Copy `allowlist.example.php` to `allowlist.php` in the project root (optional; see domain allowlist below).
3. Set `target_url` to the final API webhook URL.
4. Set `bridge_secret` in `config.php` if you want header-based auth on the bridge (recommended when the domain allowlist is off).
5. Deploy `webhook.php` and the `includes/` folder on a PHP host with cURL enabled. Commit `includes/allowedlist.php` from the repo — do **not** confuse it with root `allowlist.php` (see Files below).
6. Configure the external service to POST to:

   `https://your-bridge-domain.example/path/to/webhook.php`

## Configuration

| Option | Purpose |
|--------|---------|
| `target_url` | Upstream API endpoint (required) |
| `timeout_seconds` | Outbound cURL timeout |
| `follow_redirects` | Whether to follow redirects to the target |
| `allowed_methods` | Methods accepted on the bridge (default `POST`) |
| `preserve_http_method` | Forward the same verb, or always use POST |
| `bridge_secret` | Optional shared secret; empty = disabled |
| `bridge_secret_header` | Header name for the secret (default `X-Passthrough-Secret`) |
| `forward_headers` | Exact incoming header names to copy |
| `forward_header_prefixes` | Also copy headers starting with these prefixes |
| `logging_enabled` | Enable metadata-only request logging |
| `log_directory` | Log folder (default `./.log`) |
| `log_file` | Active log file name (default `events.log`) |
| `log_max_size_bytes` | Rotate when file reaches this size (default 2MB) |
| `log_max_age_days` | Rotate when file age reaches this limit (default 30 days) |

The request body is read from `php://input` and sent unchanged as the outbound body, so JSON, form-encoded, and binary payloads stay intact.

## Securing the bridge

You can lock down `webhook.php` in two ways (or combine them):

| Method | Config | Best for |
|--------|--------|----------|
| **Bridge secret** | `bridge_secret` + `bridge_secret_header` in `config.php` | Server-to-server webhooks that can send a custom header |
| **Domain allowlist** | `allowlist.php` in the project root | Browser or proxy callers that send `Origin`, `Referer`, or `X-Sender-Domain` |

Checks run in this order on each POST: allowed method → bridge secret (if set) → domain allowlist (if enabled) → forward to `target_url`.

### Bridge secret (header auth)

Set a long random value in `config.php`:

```php
'bridge_secret' => 'your-long-random-secret-here',
'bridge_secret_header' => 'X-Passthrough-Secret',
```

The sending service must include that header on every request to the bridge. Missing or wrong values return `403` with `"Invalid or missing bridge secret."` — the request never reaches `target_url`.

Example caller request:

```http
POST /path/to/webhook.php HTTP/1.1
Content-Type: application/json
Authorization: Bearer upstream-api-key
X-Passthrough-Secret: your-long-random-secret-here

{"id": 42}
```

**Two different headers:**

| Header | Purpose |
|--------|---------|
| `bridge_secret_header` (e.g. `X-Passthrough-Secret`) | Authenticates **to the bridge** — checked and consumed by the bridge |
| `Authorization` | Forwarded **to the upstream API** — authenticates with the receiving service |

These are independent. The bridge secret stops arbitrary traffic; `Authorization` is whatever the target API expects.

**Keep the bridge secret off the upstream call:** by default, `forward_header_prefixes` includes `X-`, so an `X-Passthrough-Secret` header would also be forwarded. To avoid that, use a header name that does not match `forward_headers` or any prefix (e.g. `Bridge-Secret`):

```php
'bridge_secret_header' => 'Bridge-Secret',
```

Callers then send `Bridge-Secret: ...` instead.

### Domain allowlist off + bridge secret on

For typical server-to-server webhooks (no `Origin` / `Referer`), disable the domain check and rely on the shared secret:

In root `allowlist.php`:

```php
'enabled' => false,
```

In `config.php`, set `bridge_secret` to a strong random string and give that value to the sending service.

## Domain allowlist (`allowlist.php`)

Separate from `config.php`. Copy `allowlist.example.php` to `allowlist.php` in the project root (same level as `webhook.php`). This is **configuration data** only — the allowlist **logic** lives in `includes/allowedlist.php` (tracked in git). Both names are similar; only root `allowlist.php` is gitignored.

| Option | Purpose |
|--------|---------|
| `enabled` | When `true`, only listed domains may call the bridge |
| `domains` | Allowed hostnames (`hooks.stripe.com`, `*.example.com`) |
| `domain_sources` | Headers checked for a URL (`Origin`, `Referer`, …) |
| `domain_header` | Optional header with a bare hostname (checked first) |

The bridge extracts a hostname from the first matching header. Examples:

- `Origin: https://hooks.stripe.com` → `hooks.stripe.com`
- `X-Sender-Domain: my-proxy.example` → `my-proxy.example`

If `enabled` is `true` but no `allowlist.php` exists, the allowlist is treated as disabled. If enabled with an empty `domains` list, requests are rejected with HTTP 500.

Many webhook providers do not send `Origin` or `Referer`. If the allowlist is enabled and no sending domain can be detected, the bridge returns `403` with `"Could not determine sending domain from request headers."` — check logs for `"sending_domain": "unknown"`.

Options:

- Have the caller (or an edge proxy) send `X-Sender-Domain` (or your configured `domain_header`) with an allowed hostname.
- Set `'enabled' => false` and use `bridge_secret` instead (see [Securing the bridge](#securing-the-bridge)).
- Restrict access at the web server (IP allowlist, basic auth).

## Logging (no payload data)

Basic request lifecycle logging is enabled by default and writes JSON lines to `.log/events.log`.

- Logged fields include timestamps, request id, method, sending domain, target endpoint, response source, upstream status, final status, and duration.
- Payload body, request packet contents, and response body contents are **never** logged.
- Error paths are also logged (method denied, auth/allowlist failures, upstream failures) via a completion event.

**Reading `response_source`:**

| Value | Meaning |
|-------|---------|
| `"bridge"` | The bridge rejected the request or failed before calling upstream. `"upstream_status"` is `null`. Common causes: missing bridge secret, allowlist failure. |
| `"upstream"` | The outbound call to `target_url` was made. `"upstream_status"` shows what the target returned. |

### Rotation policy

The log uses a single active file and is reset when either limit is reached:

- `log_max_size_bytes` (default `2MB`)
- `log_max_age_days` (default `30`)

This keeps one small rolling file for operational diagnostics.

### Prevent public log access

The `.log` folder includes server deny rules:

- `.log/.htaccess` blocks direct HTTP access on Apache.
- `.log/web.config` blocks direct HTTP access on IIS.

If you use Nginx, add an equivalent deny rule for the `/payload-passthrough/.log/` path in your site config.

```nginx
# Deny direct HTTP access to payload-passthrough logs
location ^~ /payload-passthrough/.log/ {
    deny all;
    return 403;
}
```

## Health check

`GET webhook.php` returns JSON status (does not expose `target_url`):

```json
{
  "status": "ok",
  "service": "payload-passthrough",
  "target_configured": true,
  "allowed_methods": ["POST"],
  "domain_allowlist_enabled": true
}
```

## Example forward

Incoming:

```http
POST /webhook.php HTTP/1.1
Content-Type: application/json
Authorization: Bearer upstream-token
X-Passthrough-Secret: bridge-shared-secret
X-Custom-Event: order.paid

{"id": 42, "amount": 19.99}
```

Outbound (to `target_url`):

- Same body bytes
- `Content-Type`, `Authorization`, and `X-Custom-Event` forwarded (per your config)
- `X-Passthrough-Secret` is checked by the bridge only (not forwarded if you use a non-`X-` header name such as `Bridge-Secret`)

## Response passthrough

The bridge is a **synchronous proxy**. Whatever the target API returns is relayed back to whoever POSTed to `webhook.php`.

```
Origin  →  POST webhook.php  →  POST target_url  →  target responds
Origin  ←  same status/body   ←  bridge relays    ←
```

### What the origin receives

When the target is reached successfully, the caller gets the **target’s** response unchanged in substance:

| From target | Back to origin |
|-------------|----------------|
| `200` + `ok` or JSON payload | Same status code and body |
| `4xx` / `5xx` + error JSON or text | Same status code and body |
| Custom headers (`Content-Type`, etc.) | Copied (hop-by-hop headers like `Transfer-Encoding` are skipped) |

Examples:

- Target returns `200` with `{"status":"ok"}` → origin sees `200` and that JSON.
- Target returns `422` with validation errors → origin sees `422` and that error payload.

Many webhook providers only check the HTTP status (2xx = success, may retry on 5xx). Others read the response body; both work as long as the target responds within `timeout_seconds`.

### When the origin does *not* get the target response

The bridge answers on its own only **before** forwarding, or when the outbound call fails:

| Situation | What the origin sees |
|-----------|----------------------|
| Invalid or missing bridge secret | `403` — bridge JSON (`"Invalid or missing bridge secret."`) |
| Allowlist on but sending domain not detected | `403` — bridge JSON (`"Could not determine sending domain from request headers."`) |
| Sending domain not on allowlist | `403` — bridge JSON (`"Sending domain is not allowed."`) |
| Network / cURL failure (timeout, DNS, connection) | `502` — bridge JSON (`"Upstream request failed."`) |
| Missing or invalid `target_url` in config | `500` — bridge JSON |
| Upstream rejects auth or payload | Same status/body from target (e.g. upstream `403`) — `"response_source": "upstream"` in logs |

Those responses are generated by the bridge, not wrapped inside the target’s format.

### Safety and operational notes

- **Lock down the bridge** — Only trusted callers should reach `webhook.php` (secret, domain allowlist, server IP rules). Anything the target returns (including error details) is visible to the caller.
- **Error leakage** — A target `500` body may include internal messages; that is passed through by design. Restrict who can hit the bridge if that matters.
- **Timeouts** — Default 30 seconds (`timeout_seconds`). If the target is slower, the origin gets a bridge `502`, not the target body; some providers will retry.
- **Large responses** — The full upstream body is buffered in memory once, like a normal PHP proxy.
- **Request vs response** — The incoming POST body and forwarded request headers are sent to the target; only the **response** is relayed back to the origin.

## Security notes

- Use HTTPS on both the bridge and `target_url`.
- Prefer `bridge_secret` for server-to-server webhooks; use the domain allowlist when callers reliably send `Origin`, `Referer`, or `X-Sender-Domain`.
- Restrict access at the web server (IP allowlist, basic auth) if the upstream only trusts your server IP.
- On Apache, the `Authorization` header may not reach PHP unless the server passes it through (e.g. `RewriteRule` setting `HTTP_AUTHORIZATION`). If upstream auth fails despite correct config, verify PHP receives the header.
- Do not commit `config.php` or root `allowlist.php` (both are gitignored). Do commit `includes/allowedlist.php`.
- Do not commit log output from `.log/*.log` (gitignored).

## Requirements

- PHP 8.0+ (uses `str_starts_with`, `str_contains`)
- cURL extension

## Files

| File | Role | In git? |
|------|------|---------|
| `webhook.php` | Entry point | Yes |
| `config.example.php` | Example bridge / forward settings | Yes |
| `config.php` | Your bridge config (`target_url`, `bridge_secret`, …) | No (gitignored) |
| `allowlist.example.php` | Example domain allowlist config | Yes |
| `allowlist.php` | Your domain allowlist config (`enabled`, `domains`, …) | No (gitignored) |
| `includes/allowedlist.php` | Allowlist logic (`load_allowlist()`, domain checks) | Yes |
| `includes/bootstrap.php` | Config load, bridge secret, header filtering | Yes |
| `includes/logging.php` | Metadata-only logging and rotation | Yes |
| `includes/passthrough.php` | cURL forward and response relay | Yes |
| `.log/.htaccess`, `.log/web.config` | Block direct public access to logs | Yes |
