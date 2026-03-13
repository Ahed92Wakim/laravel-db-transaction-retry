<?php

namespace DatabaseTransactions\RetryHelper\Support;

class SerializationHelper
{
    /**
     * Safely encode a value to JSON, returning null on failure.
     */
    public static function encodeJson(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}
