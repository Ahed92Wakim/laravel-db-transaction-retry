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
    | Toggle State Path
    |--------------------------------------------------------------------------
    |
    | The retry toggle uses a marker file to persist the enabled/disabled state
    | across processes. Configure where that marker is stored to ensure it is
    | writable in production deployments.
    |
    */

    'state_path' => env('DB_TRANSACTION_RETRY_STATE_PATH', storage_path('database-transaction-retry/runtime')),

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
    | Control how retry attempts are recorded. Retry events are persisted to
    | the transaction_retry_events table by default; publish and run the
    | package migration to enable storage.
    |
    */

    'logging' => [
        'table' => env('DB_TRANSACTION_RETRY_LOG_TABLE', 'transaction_retry_events'),

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
    | and slow queries.
    |
    */

    'slow_transactions' => [
        'enabled'                  => env('DB_SLOW_TRANSACTION_ENABLED', true),
        'transaction_threshold_ms' => (int) env('DB_SLOW_TRANSACTION_THRESHOLD_MS', 1),
        'slow_query_threshold_ms'  => (int) env('DB_SLOW_TRANSACTION_QUERY_THRESHOLD_MS', 1),
        'log_table'                => env('DB_SLOW_TRANSACTION_LOG_TABLE', 'db_transaction_logs'),
        'query_table'              => env('DB_SLOW_TRANSACTION_QUERY_TABLE', 'db_query_logs'),
        'log_connection'           => env('DB_SLOW_TRANSACTION_LOG_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Query Logging
    |--------------------------------------------------------------------------
    |
    | Log requests that execute at least one database query. Each request is
    | persisted to the request log table, and every query is stored in the
    | shared query log table via a morph relation.
    |
    */

    'request_logging' => [
        'enabled'        => env('DB_REQUEST_LOG_ENABLED', true),
        'log_table'      => env('DB_REQUEST_LOG_TABLE', 'db_request_logs'),
        'query_table'    => env('DB_REQUEST_LOG_QUERY_TABLE', 'db_query_logs'),
        'log_connection' => env('DB_REQUEST_LOG_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Configure the embedded dashboard UI and API endpoints. The UI is served
    | from the published static assets under public/vendor/laravel-db-transaction-retry/{dashboard.path}.
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
        'enabled' => env('DB_TRANSACTION_RETRY_API_ENABLED', true),
        'prefix'  => env('DB_TRANSACTION_RETRY_API_PREFIX', 'api/transaction-retry'),

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
    | Configure the database errors that should trigger a retry.
    |
    */

    'retry_on_deadlock' => env('DB_TRANSACTION_RETRY_ON_DEADLOCK', true),

    'retry_on_lock_wait_timeout' => env('DB_TRANSACTION_RETRY_ON_LOCK_WAIT_TIMEOUT', false),

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
