# LiteGig Production Setup

LiteGig production is the PHP + SQLite app. Static demos are maintainer-only and are not part of user deployment.

## 1. Use the Shared-Host Zip

Get the provided `dist/litegig-shared-*.zip` artifact, upload it to cPanel or shared hosting, extract it into a folder such as `public_html/litegig/`, then follow the package `README.txt`.

The package contains only runtime files: `.htaccess`, `.env.example`, `litegig.php`, `health.php`, `app/`, `brand/`, `styles/`, PWA files, selected runtime tools, monitoring example, and setup/security notes.

## 2. Enable PHP Extensions

Use PHP 8.0 or newer with:

- `pdo`
- `pdo_sqlite`
- `sqlite3`
- `fileinfo`

## 3. Configure `.env`

Copy `.env.example` to `.env`.

For `public_html/litegig/`:

```ini
LITEGIG_DATA_DIR=./litegig_data
LITEGIG_DB_PATH=./litegig_data/litegig.db
LITEGIG_UPLOAD_DIR=../litegig_uploads
LITEGIG_BACKUP_DIR=./litegig_data/backups
LITEGIG_LOG_PATH=./litegig_data/litegig_error.log
LITEGIG_CURRENCY=USD
LITEGIG_LOCALE=en_US
LITEGIG_TIMEZONE=UTC
LITEGIG_HTTP_CRON_ENABLED=false
LITEGIG_CRON_TOKEN=replace-with-a-long-random-token
LITEGIG_EMAIL_ENABLED=false
LITEGIG_SMS_ENABLED=false
LITEGIG_SMS_DRIVER=log
LITEGIG_ALLOW_LOG_ONLY_NOTIFICATIONS=true
LITEGIG_PAYMENT_GATEWAY_ENABLED=false
LITEGIG_ALLOW_LOCAL_ERROR_LOGS=true
LITEGIG_SAMPLE_DATA_ENABLED=false
```

Keep uploads outside the public app folder when possible. LiteGig writes `.htaccess` deny files into runtime directories as a fallback.

Email, SMS, payment gateway, and error webhooks are feature-flagged. Manual payment and logged notifications are valid low-infrastructure defaults; configure providers only when you have real adapters.

## 4. Initialize Production

1. Visit `https://your-domain.example/litegig/litegig.php`.
2. Register the first real production admin.
3. If you used a temporary seed/bootstrap admin, create a second real admin in **Admin Console -> Create production admin**.
4. Disable the seed/bootstrap admin only after confirming the real admin can log in.
5. Reset or rotate seed passwords and confirm old seed passwords fail.
6. Run a backup immediately after rotation.

Seed/demo users are:

- `requester@example.test`
- `runner@example.test`

They should only exist in private demo/staging environments. Keep `LITEGIG_SAMPLE_DATA_ENABLED=false` in production.

## 5. Permissions

```sh
chmod 750 litegig_data
chmod 750 ../litegig_uploads
chmod 640 .env
find app brand styles monitoring tools -type d -exec chmod 755 {} \;
find app tools -type f -name '*.php' -exec chmod 644 {} \;
find brand styles monitoring -type f -exec chmod 644 {} \;
chmod 644 litegig.php health.php manifest.webmanifest litegig-sw.js litegig-pwa.js offline.html README.txt SETUP.md SECURITY.md
```

## 6. Maintenance and Cron

Dry-run:

```sh
php tools/maintenance.php --backup --prune-business-data
```

Apply:

```sh
php tools/maintenance.php --apply --backup
```

cPanel cron:

```cron
30 2 * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/tools/maintenance.php --apply --backup >/dev/null 2>&1
*/10 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/litegig.php action=cron_notifications token=replace-with-a-long-random-token >/dev/null 2>&1
15 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/litegig.php action=cron_cleanup token=replace-with-a-long-random-token >/dev/null 2>&1
```

VPS cron uses the same commands with your PHP path and deployment path.
Keep `LITEGIG_HTTP_CRON_ENABLED=false` when cron runs through PHP CLI. Set it to `true` only if your host must call cron over HTTP, then use a long random `LITEGIG_CRON_TOKEN`.

## 7. Health and Audit

From SSH if available:

```sh
php tools/migrate.php up
php tools/maintenance.php --apply --backup
php tools/production_audit.php
```

Then open:

```text
https://your-domain.example/litegig/health.php
```

`ready=true` requires database integrity, migration ledger, an active admin, no failed notifications, and a verified backup.

## 8. Other Production Paths

VPS/SSH: extract the provided shared-host zip, then `rsync -avz litegig/ user@host:/var/www/litegig/`.

Vercel: get the provided `dist/litegig-vercel.zip`, extract it, upload the extracted fileset to GitHub, import it in Vercel, and deploy. Do not upload `dist/litegig-shared-*.zip` to Vercel.

LLM-assisted: give the deployment agent this prompt:

```text
Deploy LiteGig as PHP 8 + SQLite. Use dist/litegig-shared-*.zip or dist/shared-host/litegig. Do not deploy tests, node_modules, dist, .git, local DBs, uploads, or old zips. Copy .env.example to .env, set LITEGIG_CRON_TOKEN, keep sample data disabled, enable pdo_sqlite/sqlite3/fileinfo, create a real production admin, run migrations, run maintenance backup, run production audit, and verify health.php.
```

For Vercel deployment, use `dist/litegig-vercel.zip` instead of the PHP shared-host zip.
