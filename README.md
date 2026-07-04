# LiteGig

LiteGig is a small production-oriented PHP 8 + SQLite app for local gig operations. Requesters post jobs, runners accept and complete them, and admins manage access, task types, payments, reports, backups, health, and audit history.

## Production Direction

The intended backend is **PHP + SQLite**. That is the simplest stack for LiteGig because PHP runs on old and cheap shared-hosting infrastructure, SQLite keeps startup cost low, no managed database is required, and backups are simple files. This is a good fit for emerging markets, small operators, shared hosting, cPanel deployments, and teams that need a practical system before they need cloud infrastructure.

`litegig.php` is the public app entrypoint. Runtime code lives in `app/`; public assets live in `brand/`, `styles/`, `manifest.webmanifest`, `litegig-pwa.js`, `litegig-sw.js`, and `offline.html`.

The browser/static demo under `vercel-demo/` is maintainer-only. It is useful for previewing workflow copy and UI states, but it is not production, has no backend authority, and should not appear in user deployment paths.

## Deployment Commands

```sh
npm run deploy:shared
```

This stages the PHP runtime and creates `dist/litegig-shared-*.zip` for upload/extract on cPanel or another shared host. The package includes `.htaccess`, `.env.example`, `litegig.php`, `health.php`, app modules, assets, runtime tools, monitoring example, and `README.txt`.

Maintainers can deploy the static demo separately:

```sh
npm run maintainer:deploy:demo
```

## Production Paths

Shared hosting: run `npm run deploy:shared`, upload the generated zip, extract it into `public_html/litegig`, copy `.env.example` to `.env`, create the first real admin, run maintenance/backup, and verify `health.php`.

VPS/SSH: run `npm run deploy:shared`, then `rsync` the staged `dist/shared-host/litegig/` directory to the server. Keep `.env`, data, uploads, backups, and logs outside public links where possible.

LLM-assisted deployment prompt:

```text
Deploy LiteGig as a PHP 8 + SQLite shared-host app. Use the generated dist/litegig-shared-*.zip or dist/shared-host/litegig directory. Do not deploy tests, node_modules, dist, .git, local SQLite files, uploads, or old zips. Copy .env.example to .env, set a long random LITEGIG_CRON_TOKEN, keep LITEGIG_SAMPLE_DATA_ENABLED=false, ensure pdo_sqlite/sqlite3/fileinfo are enabled, create a real production admin, run php tools/migrate.php up, run php tools/maintenance.php --apply --backup, run php tools/production_audit.php, and verify health.php returns ready=true after the backup.
```

Vercel production: the current production backend is PHP + SQLite, which Vercel does not run natively as this app is written. A Vercel production deployment would require a native port to a supported runtime and database. Until then, Vercel is only for the maintainer static demo.

## First Deploy Hardening

Seed/demo credentials are only for private demos:

- `requester@example.test`
- `runner@example.test`
- any first-run or seed admin you created only to bootstrap production

Immediately after production deployment:

1. Create a real production admin from the Admin Console.
2. Confirm the new admin can log in.
3. Disable the seed/admin bootstrap account from the Admin Console.
4. Reset the old seed/admin password or remove sample users where appropriate.
5. Confirm old seed passwords fail.
6. Run `php tools/maintenance.php --apply --backup`.

Never disable the current active admin session or the last active admin; the app enforces both guards and audits access changes with reason, before, and after snapshots.

## Maintenance

Dry-run first:

```sh
php tools/maintenance.php --backup --prune-business-data
```

Apply maintenance:

```sh
php tools/maintenance.php --apply --backup
php tools/maintenance.php --apply --prune-business-data --terminal-days=730
```

The maintenance CLI supports verified SQLite backups, old audit cleanup, expired rate-limit cleanup, processed idempotency cleanup, sent/failed notification cleanup, and explicit old terminal business-data pruning.

Cron examples:

```cron
30 2 * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/tools/maintenance.php --apply --backup >/dev/null 2>&1
*/10 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/litegig.php action=cron_notifications token=YOUR_LONG_TOKEN >/dev/null 2>&1
15 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/litegig.php action=cron_cleanup token=YOUR_LONG_TOKEN >/dev/null 2>&1
```

## Local Development

```sh
cp .env.example .env
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080/litegig.php`.

## Verification

Run `php -l litegig.php`, lint the files under `app/`, `tests/`, and `tools/`, run `php tests/authz_matrix.php`, `php tests/status_transitions.php`, `php tests/domain_units.php`, `php tests/task_types.php`, `php tests/attachments.php`, `php tests/user_status.php`, `php tests/admin_access.php`, `php tests/privacy.php`, `php tests/notifications.php`, `php tests/m3_flows.php`, `php tests/admin_reporting.php`, `php tests/migrations.php`, `php tests/backups.php`, `php tests/formatting.php`, `php tests/i18n.php`, `php tests/production_audit.php`, `php tests/health.php`, `php tests/performance_static.php`, `php tests/accessibility_static.php`, `php tests/mobile_static.php`, `php tests/pwa_static.php`, `php tools/validate_pages_config.php`, `php tools/migrate.php status`, `php tools/production_audit.php`, `php tools/security_scan.php litegig.php`, `php tools/secret_scan.php`, `php tools/dependency_scan.php`, and `npm run deploy:shared` before deploying changes.

For browser/server checks, use temporary SQLite databases. Run `php tools/access_control_flow.php http://127.0.0.1:8765` against a fresh server first, then use a separate fresh server for `php tools/http_smoke.php`, `php tools/load_smoke.php`, `php tools/e2e_happy_path.php`, and `node tools/render_smoke.js http://127.0.0.1:8765`.

For the release Lighthouse gate, audit `http://127.0.0.1:8765/index.html` and one app screen such as `http://127.0.0.1:8765/litegig.php?action=register` with `npx -y lighthouse@latest --only-categories=performance,accessibility,best-practices`; each category should score at least 90.
