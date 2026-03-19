<?php

namespace DatabaseTransactions\RetryHelper\Support;

use DatabaseTransactions\RetryHelper\Support\LogDrivers\DatabaseLogDriver;

class TransactionRetryLogWriter
{
    /**
     * Persist the retry event.
     */
    public static function write(array $payload, string $level = 'error'): void
    {
        (new DatabaseLogDriver())->write($payload, $level);
    }
}
