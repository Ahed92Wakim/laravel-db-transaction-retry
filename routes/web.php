<?php

use DatabaseTransactions\RetryHelper\Http\Controllers\TransactionRetryEventController;
use DatabaseTransactions\RetryHelper\Support\DashboardAssets;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

$apiEnabled = (bool) config('database-transaction-retry.api.enabled', true);

$dashboardMiddleware = Arr::wrap(config('database-transaction-retry.dashboard.middleware', []));
$dashboardMiddleware = array_values(array_filter($dashboardMiddleware, static fn ($middleware) => $middleware !== null && $middleware !== ''));

$apiMiddleware = Arr::wrap(config('database-transaction-retry.api.middleware', []));
$apiMiddleware = array_values(array_filter($apiMiddleware, static fn ($middleware) => $middleware !== null && $middleware !== ''));

// Dashboard routes
$dashboardPath = DashboardAssets::dashboardPath();

Route::prefix($dashboardPath)
    ->middleware($dashboardMiddleware)
    ->group(function (): void {
        Route::get('/{path?}', function (?string $path = null) {
            $path = $path === null ? '' : ltrim($path, '/');

            if ($path !== '' && ! str_contains($path, '..')) {
                $assetPath   = DashboardAssets::assetPath($path);
                $contentType = DashboardAssets::contentTypeFor($assetPath);
                $headers     = $contentType !== null ? ['Content-Type' => $contentType] : [];

                if (is_file($assetPath)) {
                    return response()->file($assetPath, $headers);
                }

                if (is_dir($assetPath)) {
                    $indexPath = rtrim($assetPath, '/') . '/index.html';

                    if (is_file($indexPath)) {
                        return response()->file($indexPath, ['Content-Type' => 'text/html; charset=UTF-8']);
                    }
                }

                $htmlPath = $assetPath . '.html';

                if (is_file($htmlPath)) {
                    return response()->file($htmlPath, ['Content-Type' => 'text/html; charset=UTF-8']);
                }
            }

            $indexPath = DashboardAssets::indexPath();

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

if ($apiEnabled) {
    Route::prefix(trim((string) config('database-transaction-retry.api.prefix', 'api/transaction-retry'), '/'))
        ->middleware($apiMiddleware)
        ->group(function (): void {
            Route::get('events', [TransactionRetryEventController::class, 'index']);
            Route::get('events/{id}', [TransactionRetryEventController::class, 'show'])->whereNumber('id');
            Route::get('metrics/today', [TransactionRetryEventController::class, 'today']);
            Route::get('metrics/traffic', [TransactionRetryEventController::class, 'traffic']);
            Route::get('metrics/routes', [TransactionRetryEventController::class, 'routes']);
            Route::get('metrics/routes-volume', [TransactionRetryEventController::class, 'routesVolume']);
            Route::get('metrics/exceptions', [TransactionRetryEventController::class, 'exceptions']);
            Route::get('metrics/exceptions/{event_hash}', [TransactionRetryEventController::class, 'exceptionGroup']);
            Route::get('metrics/queries-volume', [TransactionRetryEventController::class, 'queriesVolume']);
            Route::get('metrics/queries-duration', [TransactionRetryEventController::class, 'queriesDuration']);
            Route::get('metrics/queries', [TransactionRetryEventController::class, 'queries']);
        });
}
