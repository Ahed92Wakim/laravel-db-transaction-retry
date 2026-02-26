<?php

namespace DatabaseTransactions\RetryHelper\Database;

use Illuminate\Database\MariaDbConnection;

class RetryableMariaDbConnection extends MariaDbConnection
{
    use RetryableConnection;
}
