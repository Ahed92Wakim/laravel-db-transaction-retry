<?php

namespace MysqlDeadlocks\RetryHelper\Support;

class BindingStringifier
{
    public static function forLogs(array $bindings): array
    {
        return array_map(static function ($binding) {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s.u');
            }
            if (is_object($binding)) {
                return '[object ' . get_class($binding) . ']';
            }
            if (is_resource($binding)) {
                return '[resource]';
            }
            if (is_string($binding)) {
                return mb_strlen($binding) > 500
                    ? (mb_substr($binding, 0, 500) . '…[+trimmed]')
                    : $binding;
            }
            if (is_array($binding)) {
                $json = @json_encode($binding, JSON_UNESCAPED_UNICODE);

                if ($json === false) {
                    return '[array]';
                }

                return mb_strlen($json) > 500
                    ? (mb_substr($json, 0, 500) . '…[+trimmed]')
                    : $json;
            }

            return $binding;
        }, $bindings);
    }
}
