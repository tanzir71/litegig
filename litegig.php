<?php
/*
README (Deployment – shared hosting, PHP 8+ / SQLite)
1) Upload `litegig.php`, the `app/` directory, and `styles/tokens.css` to your web root (e.g., `public_html/`).
2) Copy `.env.example` to `.env`; keep `litegig_data/` and `litegig_uploads/` writable by PHP.
3) Visit `https://your-domain.com/litegig.php` to auto-initialize the database.
4) Register the first user — it becomes admin automatically.
5) Admin: manage Task Types via “Task Types” (schema-driven templates).
6) Optional private demos: set `LITEGIG_SAMPLE_DATA_ENABLED=true`, then Admin → “Load Sample Data”.
7) Optional: toggle CSV PII export with `LITEGIG_EXPORT_PII=true`.
8) Optional email/SMS: set `LITEGIG_EMAIL_ENABLED=true` or configure the SMS webhook adapter.
9) Optional cron: run `php litegig.php action=cron_cleanup token=YOUR_TOKEN` or `action=cron_notifications`.
10) Manual payments are default; optional gateway adapters use signed webhooks and store no card data.
11) Back up the configured SQLite database regularly.
*/

/*
Customize in `app/bootstrap.php` / `.env`
- accentColor, fee percent, session timeout, upload directory
- set secrets and deployment-specific paths in `.env`
- adjust default task types in `app/database.php`
*/

declare(strict_types=1);

define('LITEGIG_ROOT', __DIR__);

require_once LITEGIG_ROOT . '/app/bootstrap.php';
require_once LITEGIG_ROOT . '/app/database.php';
require_once LITEGIG_ROOT . '/app/http.php';
require_once LITEGIG_ROOT . '/app/models/task_types.php';
require_once LITEGIG_ROOT . '/app/models/requests.php';
require_once LITEGIG_ROOT . '/app/models/users.php';
require_once LITEGIG_ROOT . '/app/services/notifications.php';
require_once LITEGIG_ROOT . '/app/services/backups.php';
require_once LITEGIG_ROOT . '/app/views/layout.php';
require_once LITEGIG_ROOT . '/app/controllers/auth.php';
require_once LITEGIG_ROOT . '/app/controllers/profiles.php';
require_once LITEGIG_ROOT . '/app/controllers/task_types.php';
require_once LITEGIG_ROOT . '/app/controllers/requests.php';
require_once LITEGIG_ROOT . '/app/controllers/admin.php';
require_once LITEGIG_ROOT . '/app/controllers/cron.php';
require_once LITEGIG_ROOT . '/app/router.php';
