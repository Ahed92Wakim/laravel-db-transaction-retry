<?php

namespace DatabaseTransactions\RetryHelper\Contracts;

interface LogDriverInterface
{
    /**
     * Write the log entry.
     */
    public function write(array $payload, string $logFileName, string $level): void;
}
