<?php

namespace DatabaseTransactions\RetryHelper\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class PackageModel extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    public static function instance(?string $table = null, ?string $connection = null): static
    {
        $model = new static();

        if (is_string($table) && trim($table) !== '') {
            $model->setTable(trim($table));
        }

        if (is_string($connection) && trim($connection) !== '') {
            $model->setConnection(trim($connection));
        }

        return $model;
    }

    public static function queryFor(?string $table = null, ?string $connection = null): Builder
    {
        return static::instance($table, $connection)->newQuery();
    }
}
