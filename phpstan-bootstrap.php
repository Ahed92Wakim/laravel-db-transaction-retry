<?php

// Define common Laravel global helpers that are used in the package to avoid PHPStan errors if it doesn't find them.

if (!function_exists('config')) {
    function config($key = null, $default = null) { return $default; }
}

if (!function_exists('request')) {
    function request($key = null, $default = null) { return null; }
}

if (!function_exists('app')) {
    function app($abstract = null, array $parameters = []) { return null; }
}

if (!function_exists('now')) {
    function now($timezone = null) { return new \DateTime(); }
}

if (!function_exists('storage_path')) {
    function storage_path($path = '') { return $path; }
}
