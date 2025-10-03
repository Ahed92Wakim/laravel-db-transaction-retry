# laravel-mysql-deadlock-retry

A lightweight helper to run Laravel database transactions with automatic retries on MySQL deadlocks and serialization failures.

Features:
- Retries DB::transaction on MySQL deadlocks (error 1213) and SQLSTATE 40001
- Exponential backoff with jitter between attempts
- Structured logging per attempt to storage/logs
- Safe in HTTP, CLI and queue contexts (request info captured when available)

Installation:
- Require the package via Composer and ensure Laravel auto-discovers the service provider (already configured).

Usage:

```
use MysqlDeadlocks\RetryHelper\DBTransactionRetryHelper as Retry;

$result = Retry::transactionWithRetry(function () {
    // Your DB logic here (queries, models, etc.)
    // Return any value and it will be returned from transactionWithRetry
}, maxRetries: 5, retryDelay: 2, logFileName: 'mysql-deadlocks-log');
```

Parameters:
- maxRetries: number of attempts (default 5)
- retryDelay: base delay in seconds; actual wait uses exponential backoff with jitter (default 5)
- logFileName: file prefix under storage/logs (default 'mysql-deadlocks-log')

Notes:
- Non-deadlock QueryException is thrown immediately.
- When attempts are exhausted, the last QueryException is thrown; if somehow no exception was thrown, a RuntimeException is raised.
