# AGENTS

This repo is a Laravel package that adds a retry wrapper around database transactions and ships a static dashboard build.

## Project layout
- `src/` package source (services, support classes, providers).
- `config/` default config published by the package.
- `database/` migrations for retry events.
- `dashboard/` Next.js app that builds static assets.
- `tests/` PHPUnit tests.

## Common commands
- Install deps: `composer install`
- Run tests: `composer test`
- Build dashboard (contributors): `cd dashboard && npm install && npm run build`

## Conventions
- Target PHP 8.2+ and Laravel 11/12.
- Prefer adding tests under `tests/` for new behavior.
- Keep public API changes documented in `README.md` when relevant.

## Notes
- Retry behavior is driven by `config/database-transaction-retry.php` (published in host apps).
- The dashboard build outputs to `dashboard/out` and is published by artisan commands.
