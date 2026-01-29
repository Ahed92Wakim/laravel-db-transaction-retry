<p align="center">
  <img src="art/logo.svg" width="250" alt="Database Transaction Retry Helper">
</p>

<p align="center">
  <a href="https://github.com/Ahed92Wakim/laravel-db-transaction-retry/actions/workflows/ci.yml">
    <img src="https://github.com/Ahed92Wakim/laravel-db-transaction-retry/actions/workflows/ci.yml/badge.svg?branch=main" alt="Tests">
  </a>
  <a href="https://packagist.org/packages/ahed92wakim/laravel-db-transaction-retry">
    <img src="https://img.shields.io/packagist/v/ahed92wakim/laravel-db-transaction-retry.svg" alt="Packagist Version">
  </a>
  <a href="LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="MIT License">
  </a>
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%7C%2012-red.svg" alt="Laravel 11 or 12">
  <img src="https://img.shields.io/badge/PHP-%5E8.2-blue.svg" alt="PHP ^8.2">
  <img src="https://img.shields.io/badge/style-PHP%20CS%20Fixer-informational.svg" alt="PHP CS Fixer">
</p>


Resilient database transactions for Laravel applications that need to gracefully handle deadlocks, serialization failures, and any other transient database errors you configure. This helper wraps `DB::transaction()` with targeted retries, retry event persistence, and exponential backoff so you can keep your business logic simple while surviving temporary contention.

## Highlights

- Retries known transient failures out of the box (SQLSTATE `40001`, MySQL driver errors `1213` and `1205`), and lets you add extra SQLSTATE codes, driver error codes, or exception classes through configuration.
- Exponential backoff with jitter between attempts to reduce stampedes under load.
- Retry events persisted to `transaction_retry_events` with request metadata, SQL, bindings, connection information, and stack traces.
- Captures exception classes and codes, making it easy to see exactly what triggered the retry.
- Optional transaction labels and log file names (when using the log driver) for easier traceability across microservices and jobs.
- Laravel package auto-discovery; no manual service provider registration required.

## Installation

```bash
composer require ahed92wakim/laravel-db-transaction-retry
```

The package ships with the `DatabaseTransactionRetryServiceProvider`, which Laravel auto-discovers. No additional setup is needed.

## Usage

```php
use DatabaseTransactions\RetryHelper\Services\TransactionRetrier as Retry;

$order = Retry::runWithRetry(
    function () use ($payload) {
        $order = Order::create($payload);
        $order->logAuditTrail();

        return $order;
    },
    maxRetries: 4,
    retryDelay: 1,
    logFileName: 'database/transaction-retries/orders',
    trxLabel: 'order-create'
);
```

`runWithRetry()` returns the value produced by your callback, just like `DB::transaction()`. If every attempt fails, the last exception is re-thrown so your calling code can continue its normal error handling.

### Parameters

| Parameter     | Default                                   | Description                                                                                                         |
| ------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `maxRetries`  | Config (`default: 3`)                     | Total number of attempts (initial try + retries).                                                                   |
| `retryDelay`  | Config (`default: 2s`)                    | Base delay (seconds). Actual wait uses exponential backoff with ±25% jitter.                                        |
| `logFileName` | Config (`default: database/transaction-retries`) | Used only when `logging.driver=log`. Written to `storage/logs/{Y-m-d}/{logFileName}.log`. Can point to subdirectories.                                   |
| `trxLabel`    | `''`                                      | Optional label injected into log titles and stored in the service container as `tx.label` for downstream consumers. |

Call the helper anywhere you would normally open a transaction—controllers, jobs, console commands, or domain services.

## Configuration

Publish the configuration file to tweak defaults globally:

```bash
php artisan vendor:publish --tag=database-transaction-retry-config
```

You can also run `php artisan db-transaction-retry:install` to publish the config, migrations, auth provider stub, and dashboard in one step.

- Key options (`config/database-transaction-retry.php`):

- `max_retries`, `retry_delay`, and `log_file_name` set the package-wide defaults when you omit parameters. Each respects environment variables (`DB_TRANSACTION_RETRY_MAX_RETRIES`, `DB_TRANSACTION_RETRY_DELAY`, `DB_TRANSACTION_RETRY_LOG_FILE`). `log_file_name` only applies when `logging.driver=log`.
- `lock_wait_timeout_seconds` lets you override `innodb_lock_wait_timeout` per attempt; set the matching environment variable (`DB_TRANSACTION_RETRY_LOCK_WAIT_TIMEOUT`) to control the session value or leave null to use the database default.
- `logging.driver` selects where retry events are stored: `database` (default) or `log`.
- `logging.table` lets you override the database table name (defaults to `transaction_retry_events`).
- `logging.channel` points at any existing Laravel log channel so you can reuse stacks or third-party drivers when using the log driver.
- `logging.levels.success` / `logging.levels.failure` / `logging.levels.attempt` let you tune the severity emitted for successful retries, per-attempt entries, and exhausted attempts (defaults: `warning`, `warning`, and `error`).
- `retryable_exceptions.sql_states` lists SQLSTATE codes that should trigger a retry (defaults to `40001`).
- `retryable_exceptions.driver_error_codes` lists driver-specific error codes (defaults to `1213` deadlocks and `1205` lock wait timeouts). Including `1205` not only enables retries but also activates the optional session lock wait timeout override when configured.
- `retryable_exceptions.classes` lets you specify fully-qualified exception class names that should always be retried.
- `dashboard.path` sets the UI path (defaults to `/transaction-retry`).
- `dashboard.middleware` lets you attach middleware to the dashboard UI route (for example `web`, `auth`, or `can:viewTransactionRetryDashboard`).
- `api.prefix` sets the JSON API prefix (defaults to `/api/transaction-retry`).
- `api.middleware` lets you attach middleware to the JSON API routes (for example `web`, `auth`, or a custom gate).

## Database Migration

Retry events are stored in the database by default. Publish the migration and run it:

```bash
php artisan db-transaction-retry:install
php artisan migrate
```

Or publish manually:

```bash
php artisan vendor:publish --tag=database-transaction-retry-migrations
php artisan migrate
```

If you switch to `logging.driver=log`, the migration is optional.

## Dashboard (Next.js UI)

The package ships a static Next.js dashboard that is published into your Laravel app's `public/` folder. By default, it is available at:

- UI: `/transaction-retry`
- API: `/api/transaction-retry/events`

### Securing the dashboard

By default the dashboard uses the `AuthorizeTransactionRetryDashboard` middleware, which only allows access in
the `local` environment until you define your own authorization logic (mirroring Telescope). The install command
publishes an `app/Providers/TransactionRetryDashboardServiceProvider.php` stub you can edit.

To customize access, define a gate and register the authorization callback in one of your application service
providers:

```php
use DatabaseTransactions\RetryHelper\TransactionRetryDashboard;
use Illuminate\Support\Facades\Gate;

Gate::define('viewTransactionRetryDashboard', function ($user) {
    return in_array($user->email, [
        // ...
    ], true);
});

TransactionRetryDashboard::auth(function ($request) {
    return app()->environment('local')
        || Gate::check('viewTransactionRetryDashboard', [$request->user()]);
});
```

Register the published service provider in `bootstrap/providers.php` (Laravel 11/12):

```php
return [
    App\Providers\TransactionRetryDashboardServiceProvider::class,
];
```

You can also swap the middleware stack for the dashboard and API routes in `config/database-transaction-retry.php`:

```php
'dashboard' => [
    'middleware' => ['web', 'auth', 'can:viewTransactionRetryDashboard'],
],

'api' => [
    'middleware' => ['web', 'auth', 'can:viewTransactionRetryDashboard'],
],
```

Define the `viewTransactionRetryDashboard` gate in your application (or swap in any other middleware).

Publish the dashboard assets:

```bash
php artisan vendor:publish --tag=database-transaction-retry-dashboard
```

The `db-transaction-retry:install` command publishes the dashboard too.

### Rebuilding the UI (package contributors)

If you are working on the package itself and want to rebuild the dashboard:

```bash
cd dashboard
npm install
npm run build
```

This writes static assets to `dashboard/out`, which are published to the host app's `public/transaction-retry` directory.

## Uninstall

When you remove the package with Composer, the service provider listens for the
`composer_package.ahed92wakim/laravel-db-transaction-retry:pre_uninstall` event and
cleans up published assets (similar to Telescope). It also removes the published
dashboard service provider from `bootstrap/providers.php` when available. It deletes:

- `config/database-transaction-retry.php`
- `app/Providers/TransactionRetryDashboardServiceProvider.php`
- Published migrations for the retry events and exception tables
- The published dashboard assets under `public/{dashboard.path}`

Database tables are not dropped automatically. If you want to remove them, drop the
tables manually or run your own cleanup migration.

## Partition Maintenance (MySQL)

The migration creates hourly partitions for MySQL. Keep partitions rolling by scheduling the command to run hourly:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('db-transaction-retry:roll-partitions --hours=24 --table=transaction_retry_events')->hourly();
Schedule::command('db-transaction-retry:roll-partitions --hours=24 --table=db_transaction_logs')->hourly();
Schedule::command('db-transaction-retry:roll-partitions --hours=24 --table=db_exceptions')->hourly();
```

Make sure your scheduler is running (for example, the standard `schedule:run` cron).

## Retry Conditions

Retries are attempted when the caught exception matches one of the configured conditions:

- `Illuminate\Database\QueryException` with a SQLSTATE listed in `retryable_exceptions.sql_states`.
- `Illuminate\Database\QueryException` with a driver error code listed in `retryable_exceptions.driver_error_codes` (defaults include `1213` deadlocks and `1205` lock wait timeouts).
- Any exception instance whose class appears in `retryable_exceptions.classes`.

Everything else (e.g., constraint violations, syntax errors, application exceptions) is surfaced immediately without logging or sleeping. If no attempt succeeds and all retries are exhausted, the last exception is re-thrown. In the rare case nothing is thrown but the loop exits, a `RuntimeException` is raised to signal exhaustion.

## Lock Wait Timeout

When `lock_wait_timeout_seconds` is configured, the retrier issues `SET SESSION innodb_lock_wait_timeout = {seconds}` on the active connection before each attempt, but only when the retry rules include the lock-wait timeout driver code (`1205`). This keeps the timeout predictable even after reconnects or pool reuse, and on drivers that do not support the statement the helper safely ignores the failure.

## Retry Event Storage

By default, retry events are stored in the `transaction_retry_events` table. Each retryable exception attempt is persisted, plus a final success or failure entry once the retrier finishes. If you prefer file logging, set `logging.driver=log` and optionally choose a log channel:

- Success after retries → a warning entry titled `"[trxLabel] [DATABASE TRANSACTION RETRY - SUCCESS] ExceptionClass (Codes) After (Attempts: x/y) - Warning"`.
- Failure after exhausting retries → an error entry titled `"[trxLabel] [DATABASE TRANSACTION RETRY - FAILED] ExceptionClass (Codes) After (Attempts: x/y) - Error"`.

Each retry event stores:

- Attempt count, maximum retries, transaction label, and retry status (attempt/success/failure).
- A retry group ID to correlate attempts and the final outcome for a single transaction.
- Exception class, SQLSTATE, driver error code, connection name, SQL, bindings, resolved raw SQL, and PDO error info when available.
- A compacted stack trace and request URL, method, authorization header length, and authenticated user ID/type (UUID or integer) when the request helper is bound.

Set `logFileName` to segment logs by feature or workload (e.g., `logFileName: 'database/queues/payments'`) when using the log driver.

## Runtime Toggle

Use the built-in Artisan commands to temporarily disable or re-enable retries without touching configuration files:

```bash
php artisan db-transaction-retry:stop  # disable retries
php artisan db-transaction-retry:start # enable retries
```

The commands write a small marker file inside the package (`storage/runtime/retry-disabled.marker`). As long as that file exists retries stay off; removing it or running `db-transaction-retry:start` brings them back. You can still set the `DB_TRANSACTION_RETRY_ENABLED` environment variable for a permanent default.

> **Heads up:** The `db-transaction-retry:start` command only removes the disable marker—it does not override an explicit `database-transaction-retry.enabled=false` configuration (including the `DB_TRANSACTION_RETRY_ENABLED=false` environment variable). Update that setting to `true` if you want retries to remain enabled after the current process.

## Helper Utilities

The package exposes dedicated support classes you can reuse in your own instrumentation:

- `DatabaseTransactions\RetryHelper\Support\TransactionRetryLogWriter` writes retry events to the configured driver (database or log).
- `DatabaseTransactions\RetryHelper\Support\TraceFormatter` converts debug backtraces into log-friendly arrays.
- `DatabaseTransactions\RetryHelper\Support\BindingStringifier` sanitises query bindings before logging.

For testing scenarios, the retrier looks for a namespaced `DatabaseTransactions\RetryHelper\sleep()` function before falling back to PHP's global `sleep()`, making it easy to assert backoff intervals without introducing delays.

## Testing the Package

Run the test suite with:

```bash
composer test
```

Tests cover the retry flow, logging behaviour, exponential backoff jitter, and non-retryable scenarios using fakes for the database and logger managers.

## Requirements

- PHP `>= 8.2`
- Laravel `>= 11.0`

## Contributing

Bugs, ideas, and pull requests are welcome. Feel free to open an issue describing the problem or improvement before submitting a PR so we can collaborate on scope.

## License

This package is open-sourced software released under the MIT License.
