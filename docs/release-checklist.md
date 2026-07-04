# LiteGig Release Checklist

Run this before real users.

- Run `npm run deploy:shared` and deploy the generated shared-host package, not the maintainer demo.
- Copy `.env.example` to `.env` and set production paths outside public links.
- Set `LITEGIG_CRON_TOKEN` to a long random value.
- Configure daily `php tools/maintenance.php --apply --backup`, periodic `cron_notifications`, and stale request `cron_cleanup`.
- Configure HTTPS at the domain level.
- Run `php tools/migrate.php status`; apply pending registered migrations with `php tools/migrate.php up` before opening traffic.
- Leave SMS, payment gateway, and error webhook adapters in log/manual mode unless real providers are configured.
- Register the first active production admin account.
- Create a second real production admin before disabling any seed/bootstrap admin.
- Disable or rotate seeded sample credentials, confirm old seed passwords fail, then run a verified backup.
- Run the CI-equivalent local checks from `README.md`.
- Run `php tools/production_audit.php` and resolve every failure. Use `--strict` when provider/logging warnings must block launch.
- Verify `health.php` or `litegig.php?action=health` from the deployment URL.
- Install the PWA from a mobile browser and run the runner job sheet smoke flow.
- Run `php tests/backups.php` or restore the newest `cron_backup` file to a separate SQLite path and confirm integrity, core tables, and migration ledger.
