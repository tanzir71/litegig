# Security

## Fixes Applied

- SQL: dynamic list/export queries now use fixed PDO prepared statements with bound parameters and whitelisted status/scope filters.
- XSS: added `htmlEscape()` and changed dynamic form JavaScript to use `textContent`/DOM nodes instead of caller-controlled `innerHTML`.
- CSRF: state-changing POST handlers validate CSRF tokens; logout is now POST-only.
- Authentication: passwords use `password_hash()` / `password_verify()`, sessions regenerate after register/login, and inactivity/absolute timeouts are configurable.
- Uploads: file size, extension, MIME, and dangerous extension checks were added. Attachments are stored in a private upload directory and served through an authorization-checked download endpoint.
- Rate limits: login and critical state-changing endpoints use a SQLite-backed per-IP/per-user counter.
- Runtime safeguards: CSP, `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` headers are sent by default.
- Config: secrets and deployment paths moved to `.env`; `.env`, logs, databases, and uploads are ignored by Git.
- Authorization: accepted/private request details and attachments are limited to admins or request participants. New requests remain visible to logged-in users so runners can accept them.

## Rotate Keys and Secrets

1. Edit `.env`.
2. Replace `LITEGIG_CRON_TOKEN` with a new long random value.
3. Update cPanel cron to use the same token.
4. Sign out active admin sessions if you suspect token exposure.

## Logging

Logs are written to `LITEGIG_LOG_PATH`.

To reduce production logging, keep:

```ini
display_errors=Off
log_errors=On
```

Extra app logging can be disabled only by setting a path that PHP cannot write to, but the safer approach is to keep logs enabled and rotate/delete them from cPanel.

## Production Hardening

- Force HTTPS/TLS at the domain level.
- Move from SQLite to Postgres or managed MySQL when concurrency grows.
- Put Cloudflare, cPanel ModSecurity, or another WAF in front of the app.
- Back up the database and upload directory daily.
- Keep PHP and cPanel extensions patched.

## Test Plan

Replace `BASE` with your deployed URL.

```sh
BASE="https://your-domain.example/litegig/litegig.php"
```

SQL injection payload against the list filter:

```sh
curl -i "$BASE?action=list_requests&status=%27%20OR%201%3D1"
```

Expected: response renders the normal request page or auth gate using the default `new` filter; it must not dump SQL errors or return all private rows.

CSRF rejection:

```sh
curl -i -X POST "$BASE?action=login" -d "email=test@example.com&password=password"
```

Expected: HTTP `400` with `Bad Request (CSRF)`.

Login rate limit:

```sh
curl -c cookies.txt "$BASE?action=login" >/tmp/login.html
TOKEN="$(grep -o 'name=\"csrf\" value=\"[^\"]*' /tmp/login.html | sed 's/.*value=\"//')"
for i in 1 2 3 4 5 6; do
  curl -i -b cookies.txt -c cookies.txt -X POST "$BASE?action=login" \
    -d "csrf=$TOKEN&email=wrong@example.com&password=bad"
done
```

Expected: the final attempts return HTTP `429` with `Too many login attempts`.
