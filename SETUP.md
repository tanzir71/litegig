# LiteGig Setup on Namecheap / cPanel

## 1. Upload Files

1. In cPanel, open **File Manager**.
2. Upload these project files to `public_html/` or a subfolder such as `public_html/litegig/`.
3. Keep `index.html`, `litegig.php`, `README.md`, `SETUP.md`, `SECURITY.md`, `.env.example`, and `tools/security_scan.php`.
4. Copy `.env.example` to `.env`.

Use the exact repository link when publishing project links: https://github.com/tanzir71/litegig

## 2. PHP Version and Extensions

In **cPanel -> MultiPHP Manager**, select PHP 8.0 or newer.

In **Select PHP Extensions**, enable:

- `pdo`
- `pdo_sqlite`
- `sqlite3`
- `fileinfo`

## 3. Configure `.env`

For a typical `public_html/litegig/` deployment:

```ini
LITEGIG_DATA_DIR=./litegig_data
LITEGIG_DB_PATH=./litegig_data/litegig.db
LITEGIG_UPLOAD_DIR=../litegig_uploads
LITEGIG_LOG_PATH=./litegig_data/litegig_error.log
LITEGIG_CRON_TOKEN=replace-with-a-long-random-token
```

`LITEGIG_UPLOAD_DIR` should be outside the public app folder when possible. The app also writes `.htaccess` denial files into the upload directory as a fallback.

## 4. Permissions

Set directories writable by the PHP user:

```sh
chmod 750 litegig_data
chmod 750 ../litegig_uploads
chmod 640 .env
chmod 644 litegig.php index.html README.md SETUP.md SECURITY.md
```

If cPanel creates the folders for you, use File Manager permissions instead.

## 5. Initialize

Visit:

```text
https://your-domain.example/litegig/litegig.php
```

Register the first user. The first user automatically becomes admin.

## 6. Optional Cron

If you want stale new requests to expire, add this cPanel cron line and replace paths/token:

```cron
15 * * * * /usr/local/bin/php /home/ACCOUNT/public_html/litegig/litegig.php action=cron_cleanup token=replace-with-a-long-random-token >/dev/null 2>&1
```

Use the same token as `LITEGIG_CRON_TOKEN`.
