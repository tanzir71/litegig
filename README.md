# LiteGig

LiteGig is a small PHP 8+ / SQLite app for local gig requests: users can post tasks, runners can accept them, both sides can record status updates, and admins can export request history.

## Links

- App entrypoint: `litegig.php`
- Landing page: `index.html`
- Browsable docs: `docs.html`
- Vercel demo: https://vercel-demo-eight-sepia.vercel.app/
- Setup guide: `SETUP.md`
- Security guide: `SECURITY.md`
- Repository: https://github.com/tanzir71/litegig

## Hosting Model

Use `index.html` and `docs.html` for static repository/GitHub Pages publishing. Use `vercel-demo/` for the static Vercel workflow preview. Use `litegig.php` on a PHP 8+ host with PDO SQLite for the interactive app. Static hosts can publish the public pages, but they do not run the PHP app unless PHP runtime support is configured separately.

`litegig.php` is the source-of-truth PHP app. `vercel-demo/` is the static Vercel preview that simulates the main workflow for hosts that cannot execute PHP.

## Product Notes

The app includes schema-driven task types, request creation, status transitions, comments/events, ratings, sample data, keyword search and filters, custody-path state visibility, private attachments, and CSV exports.

## Security Notes

This hardening pass added centralized escaping with `htmlEscape()`, CSRF on state-changing forms, fixed prepared SQL for filtered queries and exports, session regeneration after login/register, shorter configurable session lifetimes, login/critical endpoint rate limits, private attachment downloads, CSP/security headers, and `.env`-based configuration.

Runtime files are ignored by Git: `.env`, SQLite databases, local logs, and upload directories.

## Local Development

Use a PHP 8+ build with `pdo_sqlite` enabled.

```sh
cp .env.example .env
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080/litegig.php`.

The current workspace does not have `php` on PATH, so syntax/runtime checks should be run on a host with PHP installed.
