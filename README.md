# laravel-mysql-deadlock-retry

A lightweight helper to run Laravel database transactions with automatic retries on MySQL deadlocks and serialization failures.

Features:
- Retries DB::transaction on MySQL deadlocks (error 1213) and SQLSTATE 40001
- Exponential backoff with jitter between attempts
- Structured logging per attempt to storage/logs
- Safe in HTTP, CLI and queue contexts (request info captured when available)
- Transaction labeling for easier debugging
- Enhanced logging with SQL query information

Installation:
- Require the package via Composer: `composer require ahed92wakim/laravel-mysql-deadlock-retry`

Usage:

```php
use MysqlDeadlocks\RetryHelper\DBTransactionRetryHelper as Retry;

$result = Retry::transactionWithRetry(function () {
    // Your DB logic here (queries, models, etc.)
    // Return any value and it will be returned from transactionWithRetry
}, maxRetries: 3, retryDelay: 2, logFileName: 'mysql-deadlocks', trxLabel: 'user-update');
```

Parameters:
- maxRetries: number of attempts (default 3)
- retryDelay: base delay in seconds; actual wait uses exponential backoff with jitter (default 2)
- logFileName: file prefix under storage/logs/{today date} (default 'database/mysql-deadlocks')
- trxLabel: transaction label for easier identification in logs (default '')

Logging:
- Logs are stored in storage/logs/{date}/ directory
- Successful transactions after retries are logged as warnings
- Failed transactions after all retries are logged as errors
- Logs include SQL queries, stack traces, and request information when available

Notes:
- Non-deadlock QueryException is thrown immediately.
- When attempts are exhausted, the last QueryException is thrown; if somehow no exception was thrown, a RuntimeException is raised.
- Requires PHP 8.2+ and Laravel 11.0+