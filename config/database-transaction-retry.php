<?php

return [
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

    'log_file_name' => env('DB_TRANSACTION_RETRY_LOG_FILE', 'database/transaction-retries'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Control how retry attempts are logged. Provide a `channel` to reuse any
    | logging channel defined in your application, supply a `config` array to
    | build a dedicated logger on the fly. When none are defined,
    | the package will continue to emit dated single-file logs per prior
    | behaviour.
    |
    */

    'logging' => [
        'channel' => env('DB_TRANSACTION_RETRY_LOG_CHANNEL'),

        'levels' => [
            'success' => env('DB_TRANSACTION_RETRY_LOG_SUCCESS_LEVEL', 'warning'),
            'failure' => env('DB_TRANSACTION_RETRY_LOG_FAILURE_LEVEL', 'error'),
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
            //1205, // MySQL lock wait timeout
        ],

        'classes' => [],
    ],
];
