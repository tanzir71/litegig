# SQLite to MySQL/MariaDB Migration Notes

LiteGig currently uses PDO SQLite by design. Move to MySQL/MariaDB only when operational evidence shows SQLite write concurrency is no longer enough.

## Readiness Checklist

- Choose the target database host and backup policy.
- Freeze writes during the migration window.
- Run `action=cron_backup` and copy the generated SQLite backup off-host.
- Export operational data with `action=export_csv&download=1&scope=all`.
- Verify `php tools/migrate.php status`, `php tests/authz_matrix.php`, `php tests/notifications.php`, `php tests/m3_flows.php`, `php tests/admin_reporting.php`, `php tests/migrations.php`, `php tests/backups.php`, and `php tests/accessibility_static.php` before and after migration.

## Schema Notes

The current schema is centralized in `app/database.php`, registered in `app/migrations.php`, and tracked in the `schema_migrations` table. Use `php tools/migrate.php status` as the release gate and `php tools/migrate.php up` for pending registered migrations. Bootstrap rollback is intentionally destructive and requires `--yes-destroy-data`, so take a backup first. Most tables use integer primary keys, text timestamps, JSON stored as text, and foreign keys. A MySQL adapter should map:

- `INTEGER PRIMARY KEY AUTOINCREMENT` to `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`.
- `TEXT` JSON fields to `JSON` where supported, or `LONGTEXT` with application validation.
- SQLite `INSERT OR IGNORE` / `ON CONFLICT` statements to MySQL `INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`.
- `PRAGMA` setup to MySQL session settings.

## Cutover Shape

1. Create the MySQL schema from the SQLite table definitions.
2. Import users, task types, requests, events, ratings, audit logs, notifications, payments, saved views, settings, webhook events, and migration rows.
3. Validate row counts table by table.
4. Run smoke checks on login, Open Pool, request detail, payment receipt, delivery OTP, admin console, reports, health, and exports.
5. Keep the SQLite backup until the first production backup from MySQL has been restored successfully in a test database.

No MySQL connection code is enabled in this build because the plan requires an owner decision before changing database infrastructure.
