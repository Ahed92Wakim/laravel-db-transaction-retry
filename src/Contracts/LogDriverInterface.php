<?php

namespace DatabaseTransactions\RetryHelper\Contracts;

interface LogDriverInterface
{
    /**
     * Write the log entry.
     *
     * @param array $payload
     * @param string $logFileName
     * @param string $level
     * @return void
     */
    public function write(array $payload, string $logFileName, string $level): void;
}
