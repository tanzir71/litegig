# LiteGig

LiteGig is a small PHP 8+ / SQLite app for local gig requests: users can post tasks, runners can accept them, both sides can record status updates, and admins can export request history.

## Links

- App entrypoint: `litegig.php`
- Landing page: `index.html`
- Setup guide: [SETUP.md](SETUP.md)
- Security guide: [SECURITY.md](SECURITY.md)
- Repository: https://github.com/tanzir71/litegig

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
