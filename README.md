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
  <img src="https://img.shields.io/badge/Laravel-%5E11-red.svg" alt="Laravel ^11">
  <img src="https://img.shields.io/badge/PHP-%5E8.2-blue.svg" alt="PHP ^8.2">
  <img src="https://img.shields.io/badge/style-PHP%20CS%20Fixer-informational.svg" alt="PHP CS Fixer">
</p>


Resilient database transactions for Laravel applications that need to gracefully handle deadlocks, serialization failures, and any other transient database errors you configure. This helper wraps `DB::transaction()` with targeted retries, structured logging, and exponential backoff so you can keep your business logic simple while surviving temporary contention.

## Highlights

- Retries known transient failures out of the box (SQLSTATE `40001`, MySQL driver errors `1213` and `1205`), and lets you add extra SQLSTATE codes, driver error codes, or exception classes through configuration.
- Exponential backoff with jitter between attempts to reduce stampedes under load.
- Structured logs with request metadata, SQL, bindings, connection information, and stack traces written to dated files under `storage/logs/{Y-m-d}`.
- Log titles include the exception class and codes, making it easy to see exactly what triggered the retry.
- Optional transaction labels and custom log file names for easier traceability across microservices and jobs.
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
| `logFileName` | Config (`default: database/transaction-retries`) | Written to `storage/logs/{Y-m-d}/{logFileName}.log`. Can point to subdirectories.                                   |
| `trxLabel`    | `''`                                      | Optional label injected into log titles and stored in the service container as `tx.label` for downstream consumers. |

Call the helper anywhere you would normally open a transaction—controllers, jobs, console commands, or domain services.

## Configuration

Publish the configuration file to tweak defaults globally:

```bash
php artisan vendor:publish --tag=database-transaction-retry-config
```

- Key options (`config/database-transaction-retry.php`):

- `max_retries`, `retry_delay`, and `log_file_name` set the package-wide defaults when you omit parameters. Each respects environment variables (`DB_TRANSACTION_RETRY_MAX_RETRIES`, `DB_TRANSACTION_RETRY_DELAY`, `DB_TRANSACTION_RETRY_LOG_FILE`).
- `lock_wait_timeout_seconds` lets you override `innodb_lock_wait_timeout` per attempt; set the matching environment variable (`DB_TRANSACTION_RETRY_LOCK_WAIT_TIMEOUT`) to control the session value or leave null to use the database default.
- `logging.channel` points at any existing Laravel log channel so you can reuse stacks or third-party drivers.
- `logging.levels.success` / `logging.levels.failure` let you tune the severity emitted for successful retries and exhausted attempts (defaults: `warning` and `error`).
- `retryable_exceptions.sql_states` lists SQLSTATE codes that should trigger a retry (defaults to `40001`).
- `retryable_exceptions.driver_error_codes` lists driver-specific error codes (defaults to `1213` deadlocks and `1205` lock wait timeouts). Including `1205` not only enables retries but also activates the optional session lock wait timeout override when configured.
- `retryable_exceptions.classes` lets you specify fully-qualified exception class names that should always be retried.

## Retry Conditions

Retries are attempted when the caught exception matches one of the configured conditions:

- `Illuminate\Database\QueryException` with a SQLSTATE listed in `retryable_exceptions.sql_states`.
- `Illuminate\Database\QueryException` with a driver error code listed in `retryable_exceptions.driver_error_codes` (defaults include `1213` deadlocks and `1205` lock wait timeouts).
- Any exception instance whose class appears in `retryable_exceptions.classes`.

Everything else (e.g., constraint violations, syntax errors, application exceptions) is surfaced immediately without logging or sleeping. If no attempt succeeds and all retries are exhausted, the last exception is re-thrown. In the rare case nothing is thrown but the loop exits, a `RuntimeException` is raised to signal exhaustion.

## Lock Wait Timeout

When `lock_wait_timeout_seconds` is configured, the retrier issues `SET SESSION innodb_lock_wait_timeout = {seconds}` on the active connection before each attempt, but only when the retry rules include the lock-wait timeout driver code (`1205`). This keeps the timeout predictable even after reconnects or pool reuse, and on drivers that do not support the statement the helper safely ignores the failure.

## Logging Behaviour

By default, logs are written using a dedicated single-file channel per day. Override `logging.channel` to integrate with your own logging stack:

- Success after retries → a warning entry titled `"[trxLabel] [DATABASE TRANSACTION RETRY - SUCCESS] ExceptionClass (Codes) After (Attempts: x/y) - Warning"`.
- Failure after exhausting retries → an error entry titled `"[trxLabel] [DATABASE TRANSACTION RETRY - FAILED] ExceptionClass (Codes) After (Attempts: x/y) - Error"`.

Each log entry includes:

- Attempt count, maximum retries, and transaction label.
- Exception class, SQLSTATE, driver error code, connection name, SQL, resolved raw SQL, and PDO error info when available.
- A compacted stack trace and sanitized bindings.
- Request URL, method, authorization header length, and authenticated user ID when the request helper is bound.

Set `logFileName` to segment logs by feature or workload (e.g., `logFileName: 'database/queues/payments'`).

## Helper Utilities

The package exposes dedicated support classes you can reuse in your own instrumentation:

- `DatabaseTransactions\RetryHelper\Support\TransactionRetryLogWriter` writes structured entries using the same format as the retrier.
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
