# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel package (`ahed92wakim/laravel-db-transaction-retry`) that wraps database transactions with configurable retry logic and ships a monitoring dashboard. Supports PHP 8.2+ and Laravel 11/12.

## Commands

```bash
composer install          # Install PHP dependencies
composer test             # Run Pest tests
composer fix              # Run PHP CS Fixer
composer fix:dry          # Dry-run code style check
vendor/bin/phpstan analyse # Static analysis (level 5, src/ only)
```

Run a single test file:
```bash
vendor/bin/pest --configuration phpunit.xml tests/Unit/TransactionRetrierTest.php
```

Build the Next.js dashboard (contributors only — static output is committed):
```bash
cd dashboard && npm install && npm run build
```

## Architecture

### Core retry flow

```
DB::transactionWithRetry() / TransactionRetrier::runWithRetry()
    └── TransactionRetrier (src/Services/)
            └── TransactionRetryLogWriter → transaction_retry_events table
```

### Monitoring flow (event-driven)

```
DatabaseTransactionRetryServiceProvider
    └── EventServiceProvider binds:
            ├── SlowTransactionMonitor → SlowTransactionWriter
            │       ├── db_transaction_logs
            │       └── db_query_logs
            ├── RequestMonitor → RequestLogWriter
            │       ├── db_request_logs
            │       └── db_query_logs
            └── QueryExceptionLogger → QueryExceptionWriter → db_exceptions
```

### Runtime toggle

`RetryToggle` (`src/Support/`) checks `config('database-transaction-retry.state_path')` — a file whose presence/absence enables or disables retries at runtime without redeploying. `StartRetryCommand` and `StopRetryCommand` create/remove this file.

### Console commands

- `InstallCommand` — publishes config, migrations, and dashboard assets
- `StartRetryCommand` / `StopRetryCommand` — create/remove the state file to toggle retries at runtime
- `RollPartitionsCommand` — rotates/partitions the log tables

### Support layer

Helpers used across monitors and writers:
- `RequestContext` — extracts current HTTP request metadata
- `TimeHelper` — duration/timing calculations
- `SerializationHelper` — normalizes exception data for storage
- `DashboardAssets` — maps URL paths to files in `dashboard/out/` with path-traversal protection

### Dashboard

Static Next.js export in `dashboard/out/` — published to the host app's `public/` directory via the install command. API routes are defined in `routes/web.php` and served by `TransactionRetryEventController` (13 endpoints). All responses use the `src/Http/Resources/` JSON resource classes.

## Key conventions

- All notable changes go in `CHANGELOG.md` under `[Unreleased]`.
- Public API changes (macro signature, config keys, new features) should be reflected in `README.md`.
- Code style is PSR-12 with short array syntax and single quotes — enforced by `.php-cs-fixer.php`.
- Tests live in `tests/Unit/` (business logic) or `tests/Feature/` (integration). The test bootstrap at `tests/bootstrap.php` mocks Laravel path helpers so tests run without a full app container.
- Retry behavior is entirely driven by `config/database-transaction-retry.php`. When adding new config keys, update both the config file and any relevant `README.md` sections.
- The dashboard static build in `dashboard/out/` is committed to the repo and should be rebuilt and re-committed when dashboard changes are made.
