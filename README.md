<p align="center">
  <img src="art/logo.svg" width="250" alt="MySQL Deadlock Retry Helper">
</p>

<p align="center">
  <a href="https://github.com/Ahed92Wakim/laravel-mysql-deadlock-retry/actions/workflows/ci.yml">
    <img src="https://github.com/Ahed92Wakim/laravel-mysql-deadlock-retry/actions/workflows/ci.yml/badge.svg?branch=main" alt="Tests">
  </a>
  <a href="https://packagist.org/packages/ahed92wakim/laravel-mysql-deadlock-retry">
    <img src="https://img.shields.io/packagist/v/ahed92wakim/laravel-mysql-deadlock-retry.svg" alt="Packagist Version">
  </a>
  <a href="LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="MIT License">
  </a>
  <img src="https://img.shields.io/badge/Laravel-%5E11-red.svg" alt="Laravel ^11">
  <img src="https://img.shields.io/badge/PHP-%5E8.2-blue.svg" alt="PHP ^8.2">
  <img src="https://img.shields.io/badge/style-PHP%20CS%20Fixer-informational.svg" alt="PHP CS Fixer">
</p>


Resilient database transactions for Laravel applications that need to gracefully handle MySQL deadlocks and serialization failures. This helper wraps `DB::transaction()` with targeted retries, structured logging, and exponential backoff so you can keep your business logic simple while surviving transient contention.

## Highlights

- Retries only known transient failure scenarios (MySQL driver error `1213` and SQLSTATE `40001`), leaving all other exceptions untouched.
- Exponential backoff with jitter between attempts to reduce stampedes under load.
- Structured logs with request metadata, SQL, bindings, connection information, and stack traces written to dated files under `storage/logs/{Y-m-d}`.
- Safe in HTTP, CLI, and queue contexts: request data is collected when available and ignored when not.
- Optional transaction labels and custom log file names for easier traceability across microservices and jobs.
- Laravel package auto-discovery; no manual service provider registration required.

## Installation

```bash
composer require ahed92wakim/laravel-mysql-deadlock-retry
```

The package ships with a service provider that is auto-discovered. No additional setup is needed, and the helper functions in `src/Helper.php` are automatically loaded.

## Usage

```php
use MysqlDeadlocks\RetryHelper\DBTransactionRetryHelper as Retry;

$order = Retry::transactionWithRetry(
    function () use ($payload) {
        $order = Order::create($payload);
        $order->logAuditTrail();

        return $order;
    },
    maxRetries: 4,
    retryDelay: 1,
    logFileName: 'mysql-deadlocks/orders',
    trxLabel: 'order-create'
);
```

`transactionWithRetry()` returns the value produced by your callback, just like `DB::transaction()`. If every attempt fails, the last `QueryException` is re-thrown so your calling code can continue its normal error handling.

### Parameters

| Parameter     | Default                    | Description                                                                                                         |
| ------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `maxRetries`  | `3`                        | Total number of attempts (initial try + retries).                                                                   |
| `retryDelay`  | `2`                        | Base delay (seconds). Actual wait uses exponential backoff with ±25% jitter.                                        |
| `logFileName` | `database/mysql-deadlocks` | Written to `storage/logs/{Y-m-d}/{logFileName}.log`. Can point to subdirectories.                                   |
| `trxLabel`    | `''`                       | Optional label injected into log titles and stored in the service container as `tx.label` for downstream consumers. |

Call the helper anywhere you would normally open a transaction—controllers, jobs, console commands, or domain services.

## Retry Conditions

Retries are attempted only when the caught exception is an `Illuminate\Database\QueryException` that matches one of:

- SQLSTATE `40001` (serialization failure).
- MySQL driver error `1213` (deadlock), whether reported via SQLSTATE or the driver error code.

Everything else (e.g., constraint violations, syntax errors, driver error `1205`, application exceptions) is surfaced immediately without logging or sleeping.

If no attempt succeeds and all retries are exhausted, the last `QueryException` is re-thrown. In the rare case nothing is thrown but the loop exits, a `RuntimeException` is raised to signal exhaustion.

## Logging Behaviour

Logs are written using a dedicated single-file channel per day:

- Success after retries → a warning entry titled `"[trxLabel] [MYSQL DEADLOCK RETRY - SUCCESS] After (Attempts: x/y) - Warning"`.
- Failure after exhausting retries → an error entry titled `"[trxLabel] [MYSQL DEADLOCK RETRY - FAILED] After (Attempts: x/y) - Error"`.

Each log entry includes:

- Attempt count, maximum retries, and transaction label.
- Connection name, SQL, resolved raw SQL (when bindings are available), and PDO error info.
- A compacted stack trace and sanitized bindings.
- Request URL, method, authorization header length, and authenticated user ID when the request helper is bound.

Set `logFileName` to segment logs by feature or workload (e.g., `logFileName: 'database/queues/payments'`).

## Testing the Package

Run the test suite with:

```bash
composer test
```

Tests cover the retry flow, logging behaviour, exponential backoff jitter, and non-deadlock scenarios using fakes for the database and logger managers.

## Requirements

- PHP `>= 8.2`
- Laravel `>= 11.0`

## Contributing

Bugs, ideas, and pull requests are welcome. Feel free to open an issue describing the problem or improvement before submitting a PR so we can collaborate on scope.

## License

This package is open-sourced software released under the MIT License.
