<?php

namespace DatabaseTransactions\RetryHelper\Database;

use Illuminate\Database\MySqlConnection;

class RetryableMySqlConnection extends MySqlConnection
{
    use RetryableConnection;
}
