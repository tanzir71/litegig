# Changelog

## Unreleased

- Split the PHP app behind `litegig.php` into bootstrap, database, model, view, controller, and router modules under `app/`.
- Updated the static security scan to inspect the modular PHP source tree.
- Documented the `app/` directory as part of PHP deployments.
- Added shared empty/error/loading/offline states across the PHP app, including nearby-location feedback and no-template request creation handling.
- Hardened the PHP app for mobile with single-column layouts at narrow widths, sticky primary actions, wrapping long content, and 16px form inputs.
- Extracted shared design tokens to `styles/tokens.css` and imported them from the landing page, docs, demo, and PHP layout.
- Reworked landing/docs copy toward operator workflows, live handoffs, and production readiness.
- Added an Open Pool for runners to discover unassigned jobs by task type, nearby distance, and fee, with quick accept actions.
- Added operator profiles with generated avatars, request stats, recent ratings, and reputation chips on request and Open Pool views.
- Added role-aware request next actions, a comment thread rendered from event-log comments, and private proof attachments with inline previews on request timelines.
- Tightened request-list ownership rules, added centralized server-side authorization helpers, and introduced a CLI authorization matrix test for admin/requester/runner/stranger access.
- Added queued email notifications for acceptance, pickup, delivery, payment, and comments, with user preferences, cron retry/log processing, and notification tests.
- Added request tracking codes and a redacted public tracking page with status path and lifecycle timeline.
- Added pagination, per-page controls, saved filter views, and supporting request indexes for the queue and Open Pool.
- Added optional pickup/delivery windows, request schedule panels, and due-soon/overdue indicators on request lists.
- Added cancellation, runner decline, dispute, and reopen exception flows with reason taxonomies, events, audits, and authz matrix coverage.
- Added a mobile runner job sheet for active assigned jobs with direct pickup/delivery actions, proof capture, due timing, and payment-state cues.
- Sorted nearby queue and Open Pool results by calculated distance after geo filtering.
- Added pre-acceptance request editing for schema fields, attachments, schedule windows, price recalculation, edit events, and audit entries.
- Added manual payment receipts, request payment panels, and a reconciliation page for outstanding vs settled amounts and recorded fees.
- Added a feature-flagged payment gateway adapter with gateway references, signed/idempotent webhook processing, and manual-payment fallback.
- Added SMS notification queueing through a pluggable log/webhook driver, phone capture, per-channel/per-event preferences, and delivery OTP notifications.
- Added hashed delivery OTP handoffs for runner delivery confirmation, with requester/admin reset flow and OTP verification on delivery.
- Added installable PWA assets, service worker caching, an offline fallback page, and IndexedDB queueing for runner pickup/delivery actions with proof files.
- Added an admin console for user admin roles, fee config, notification templates, audit review, migration records, and operational shortcuts.
- Added reporting for status/type/runner volume, completion and first-attempt rates, ratings, earnings, fees, and CSV/Excel export.
- Added a health JSON endpoint and cron-triggered SQLite backups with retention.
- Added app skip-link/main-landmark accessibility improvements and a static accessibility regression check.
- Added a GitHub Actions CI workflow for PHP linting, JavaScript syntax checks, tests, security scan, secret scan, dependency scan, and manifest validation.
- Added JSON error logging, optional signed error webhooks, provider decision records, release checklist, uptime monitor example, and SQLite to MySQL/MariaDB migration notes.
- Added a versioned migration registry, guarded CLI migration status/apply/rollback tool, and migration regression test.
- Added verified SQLite backup creation plus a backup restore regression test that checks integrity, required tables, migration ledger, and restored operational rows.
- Added reusable HTTP smoke and load-smoke scripts, and wired them into CI with a temporary PHP server.
- Added a full HTTP E2E happy-path script and PWA static regression checks for manifest, service worker, and offline queue wiring.
- Added locale-aware currency formatting, timezone-aware timestamp display, and local-date report bounds with formatting regression coverage.
- Added a production audit CLI, disabled sample-data loading by default, and created deny-file fallbacks for runtime data, backup, and upload directories.
- Added a shared i18n catalog for workflow labels, status chips, nav labels, notification event names, due badges, and request state path labels with locale fallback tests.
- Added Excel-compatible request-history export alongside CSV, shared export writers, PII clamping, and spreadsheet formula guards.
- Added a centralized request status-transition ruleset, shared SQL race guards, and lifecycle regression coverage for workflow gates.
- Added domain unit coverage for price parsing, fee clamping, schema metadata validation, geo distance, and rating aggregation.
- Added task-type archive/restore support so operational schemas are hidden from new work without hard-deleting historical records.
- Added private attachment resolver hardening and regression coverage for auth-gated request/event attachment access.
- Added mobile static regression coverage for 390px-oriented viewport metadata, overflow guards, 44px targets, single-column app behavior, and static/demo breakpoints.
- Added public tracking privacy coverage for redacted titles, constrained summaries, and lifecycle-only timelines without event notes or attachment labels.
- Added a dependency-free Chrome render smoke for landing, docs, demo, offline, and app screens at 390px and desktop widths.
- Added browser-level accessibility checks for language/main landmarks, image alt attributes, command names, and form-control labels.
- Fixed production audit backup detection so verified `litegig-*.db` backups created by cron are recognized and integrity-checked.
- Added active/suspended user status management, suspended-account login/session gates, notification suppression, and active-admin launch audit coverage.
- Expanded the health endpoint with readiness checks for DB integrity, migration ledger, active admin, notification failures, and latest verified backup.
- Added browser navigation timing, resource count, and encoded transfer budgets to the Chrome render smoke.
- Added lazy-loaded, async-decoded proof image previews with stable dimensions and static performance regression coverage.
- Removed render-blocking Google Fonts requests with a shared local-first Inter/system stack across all LiteGig surfaces.
- Aligned production docs around PHP + SQLite, moved Vercel/static demos to maintainer-only language, and added shared-host/VPS/LLM-assisted deployment paths.
- Added `npm run deploy:shared`, a runtime-only shared-host package builder, root `.htaccess`, `health.php`, and package `README.txt` generation.
- Added `tools/maintenance.php` for dry-run/apply SQLite backups, audit/rate-limit/idempotency cleanup, notification pruning, and explicit terminal business-data pruning.
- Added Pages/Jekyll exclusions plus validation for backend/runtime/private artifacts.
- Added audited admin access operations for production admin creation, password reset, account activation/disablement, last-admin/current-session guards, and reasoned before/after audit snapshots.

## 2026-07-03

- Added browsable `docs.html` with setup, demo account, workflow, deployment, security, troubleshooting, and roadmap sections.
- Reworked the landing page around compact typography, HTML docs links, clear app/docs/sample CTAs, and hosting responsibility separation.
- Added request keyword search and a state-driven custody path panel to the PHP app.
- Synced the app footer/favicon with the static landing and docs pages.
- Added and deployed the static Vercel demo at https://vercel-demo-eight-sepia.vercel.app/.

## 2026-05-14

- Hardened SQL filtering/export paths with fixed PDO prepared statements.
- Added output escaping, safer dynamic form rendering, CSRF-protected logout, session regeneration, session timeouts, rate limiting, private attachment downloads, upload validation, security headers, and `.env` configuration.
- Added RoughCut-inspired landing page, setup/security docs, static security scan, and production migration notes.
