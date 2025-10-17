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

    'max_retries' => (int) env('MYSQL_DEADLOCK_MAX_RETRIES', 3),

    'retry_delay' => (int) env('MYSQL_DEADLOCK_RETRY_DELAY', 2),

    'log_file_name' => env('MYSQL_DEADLOCK_LOG_FILE', 'database/mysql-deadlocks'),

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
        'channel' => env('MYSQL_DEADLOCK_LOG_CHANNEL'),

        'config' => null,

        'levels' => [
            'success' => env('MYSQL_DEADLOCK_LOG_SUCCESS_LEVEL', 'warning'),
            'failure' => env('MYSQL_DEADLOCK_LOG_FAILURE_LEVEL', 'error'),
        ],
    ],
];

