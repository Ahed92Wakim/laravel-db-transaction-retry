<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Toggle
    |--------------------------------------------------------------------------
    |
    | Enable or disable the retry helper at runtime. This can be overridden
    | through the provided Artisan commands when a cache store is available.
    |
    */

    'enabled' => env('DB_TRANSACTION_RETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retry Defaults
    |--------------------------------------------------------------------------
    |
    | Configure the retry strategy that will be used when no explicit overrides
    | are provided to the retrier helper. These values can be fine-tuned per
    | environment through the accompanying environment variables.
    |
    */

    'max_retries' => (int) env('DB_TRANSACTION_RETRY_MAX_RETRIES', 3),

    'retry_delay' => (int) env('DB_TRANSACTION_RETRY_DELAY', 2),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Control how retry attempts are recorded. Set `driver` to "database" to
    | persist retry events to the transaction_retry_events table (publish and
    | run the migration), or "log" to keep file/channel logging. Provide a
    | `channel` to reuse any logging channel defined in your application, or
    | supply a `config` array to build a dedicated logger on the fly.
    |
    */

    'log_file_name' => env('DB_TRANSACTION_RETRY_LOG_FILE', 'database/transaction-retries'),

    'logging' => [
        'driver'  => env('DB_TRANSACTION_RETRY_LOG_DRIVER', 'database'),
        'table'   => env('DB_TRANSACTION_RETRY_LOG_TABLE', 'transaction_retry_events'),
        'channel' => env('DB_TRANSACTION_RETRY_LOG_CHANNEL'),
        'config'  => [],

        'levels' => [
            'success' => env('DB_TRANSACTION_RETRY_LOG_SUCCESS_LEVEL', 'warning'),
            'failure' => env('DB_TRANSACTION_RETRY_LOG_FAILURE_LEVEL', 'error'),
            'attempt' => env('DB_TRANSACTION_RETRY_LOG_ATTEMPT_LEVEL', 'warning'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Exception Logging
    |--------------------------------------------------------------------------
    |
    | Persist unhandled database query exceptions to a dedicated table for
    | later inspection. This hooks into Laravel's exception reporting pipeline
    | through the package service provider.
    |
    */

    'exception_logging' => [
        'enabled'    => env('DB_QUERY_EXCEPTION_LOG_ENABLED', true),
        'table'      => env('DB_QUERY_EXCEPTION_LOG_TABLE', 'db_exceptions'),
        'connection' => env('DB_QUERY_EXCEPTION_LOG_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Transaction Monitoring
    |--------------------------------------------------------------------------
    |
    | Track slow database transactions and persist summary data for analysis.
    | Configure thresholds in milliseconds and the tables that store summaries
    | and slow queries. Logging can also write to the default or named channel.
    |
    */

    'slow_transactions' => [
        'enabled'                  => env('DB_SLOW_TRANSACTION_ENABLED', true),
        'transaction_threshold_ms' => (int) env('DB_SLOW_TRANSACTION_THRESHOLD_MS', 1),
        'slow_query_threshold_ms'  => (int) env('DB_SLOW_TRANSACTION_QUERY_THRESHOLD_MS', 1),
        'log_table'                => env('DB_SLOW_TRANSACTION_LOG_TABLE', 'db_transaction_logs'),
        'query_table'              => env('DB_SLOW_TRANSACTION_QUERY_TABLE', 'db_transaction_queries'),
        'log_connection'           => env('DB_SLOW_TRANSACTION_LOG_CONNECTION'),
        'log_enabled'              => env('DB_SLOW_TRANSACTION_LOG_ENABLED', true),
        'log_channel'              => env('DB_SLOW_TRANSACTION_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Configure the embedded dashboard UI and API endpoints. The UI is served
    | from the published static assets under the configured path.
    |
    */

    'dashboard' => [
        'enabled' => env('DB_TRANSACTION_RETRY_DASHBOARD_ENABLED', true),
        'path'    => env('DB_TRANSACTION_RETRY_DASHBOARD_PATH', 'transaction-retry'),

        /*
        | Middleware to apply to the dashboard UI route. Use "web" for session
        | auth and add "auth" or "can:ability" to enforce access control.
        */
        'middleware' => [
            'web',
            DatabaseTransactions\RetryHelper\Http\Middleware\AuthorizeTransactionRetryDashboard::class,
        ],
    ],

    'api' => [
        'enabled'    => env('DB_TRANSACTION_RETRY_API_ENABLED', true),
        'prefix'     => env('DB_TRANSACTION_RETRY_API_PREFIX', 'api/transaction-retry'),

        /*
        | Middleware to apply to the dashboard JSON API routes. For web-session
        | auth, include "web" and "auth" (or a custom middleware/gate).
        */
        'middleware' => [
            'web',
            DatabaseTransactions\RetryHelper\Http\Middleware\AuthorizeTransactionRetryDashboard::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retryable Exceptions
    |--------------------------------------------------------------------------
    |
    | Configure the database errors that should trigger a retry. SQLSTATE codes
    | and driver error codes are checked for `QueryException` instances. You may
    | also list additional exception classes to retry on by name.
    |
    */

    'retryable_exceptions' => [
        'sql_states' => [
            '40001', // Serialization failure
        ],

        'driver_error_codes' => [
            1213, // MySQL deadlock
            // 1205, // MySQL lock wait timeout
        ],

        'classes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lock Wait Timeout
    |--------------------------------------------------------------------------
    |
    | Optionally override the session-level lock wait timeout before executing
    | the transaction. When set to a positive integer the helper issues:
    | "SET SESSION innodb_lock_wait_timeout = {seconds}" on the active
    | connection prior to each attempt. Set to null to leave the database
    | default untouched.
    |
    */

    'lock_wait_timeout_seconds' => env('DB_TRANSACTION_RETRY_LOCK_WAIT_TIMEOUT', 50),
];
