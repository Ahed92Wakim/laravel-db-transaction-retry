<?php

use Illuminate\Support\Facades\Route;

Route::middleware(config('database-transaction-retry.dashboard.middleware', []))
    ->prefix(trim((string) config('database-transaction-retry.dashboard.path', 'transaction-retry'), '/'))
    ->group(function (): void {
        Route::get('/{path?}', function () {
            $path = trim((string) config('database-transaction-retry.dashboard.path', 'transaction-retry'), '/');
            $path = $path === '' ? 'transaction-retry' : $path;

            $indexPath = function_exists('public_path')
                ? public_path($path . '/index.html')
                : app()->basePath('public/' . $path . '/index.html');

            if (! is_file($indexPath)) {
                $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Transaction Retry Dashboard</title></head>'
                    . '<body><main style="font-family: ui-sans-serif, system-ui; padding: 2rem;">'
                    . '<h1>Hello World</h1><p>The dashboard assets are not published yet.</p>'
                    . '</main></body></html>';

                return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            return response()->file($indexPath, ['Content-Type' => 'text/html; charset=UTF-8']);
        })->where('path', '.*');
    });
