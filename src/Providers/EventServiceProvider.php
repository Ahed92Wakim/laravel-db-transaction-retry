<?php

namespace DatabaseTransactions\RetryHelper\Providers;

use DatabaseTransactions\RetryHelper\Support\RequestMonitor;
use DatabaseTransactions\RetryHelper\Support\RetryToggle;
use DatabaseTransactions\RetryHelper\Support\SlowTransactionMonitor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerSlowTransactionMonitor();
        $this->registerRequestMonitor();
    }

    protected function registerSlowTransactionMonitor(): void
    {
        if (! function_exists('config')) {
            return;
        }

        $config = config('database-transaction-retry.slow_transactions', []);
        if (! is_array($config) || ! ($config['enabled'] ?? true)) {
            return;
        }

        if (! $this->app->bound('events')) {
            return;
        }

        $this->app->singleton(SlowTransactionMonitor::class, static function () use ($config): SlowTransactionMonitor {
            return new SlowTransactionMonitor($config);
        });

        $events = $this->app['events'];

        $events->listen(TransactionBeginning::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleTransactionBeginning($event);
        });

        $events->listen(TransactionCommitted::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleTransactionCommitted($event);
        });

        $events->listen(TransactionRolledBack::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleTransactionRolledBack($event);
        });

        $events->listen(QueryExecuted::class, function ($event): void {
            $this->app->make(SlowTransactionMonitor::class)->handleQueryExecuted($event);
        });

        if (class_exists(RequestHandled::class)) {
            $events->listen(RequestHandled::class, function ($event): void {
                $this->app->make(SlowTransactionMonitor::class)->handleRequestHandled($event);
            });
        }

        if ($config['exclude_queue'] ?? true) {
            if (class_exists(CommandStarting::class)) {
                $events->listen(CommandStarting::class, function ($event): void {
                    $this->app->make(SlowTransactionMonitor::class)
                        ->handleQueueCommandStarting((string) ($event->command ?? ''));
                });
            }

            if (class_exists(CommandFinished::class)) {
                $events->listen(CommandFinished::class, function ($event): void {
                    $this->app->make(SlowTransactionMonitor::class)
                        ->handleQueueCommandFinished((string) ($event->command ?? ''));
                });
            }

            if (class_exists(JobProcessing::class)) {
                $events->listen(JobProcessing::class, function (): void {
                    $this->app->make(SlowTransactionMonitor::class)->handleJobProcessing();
                });
            }

            foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $jobEvent) {
                if (class_exists($jobEvent)) {
                    $events->listen($jobEvent, function (): void {
                        $this->app->make(SlowTransactionMonitor::class)->handleJobFinished();
                    });
                }
            }
        }
    }

    protected function registerRequestMonitor(): void
    {
        if (! function_exists('config')) {
            return;
        }

        $config = config('database-transaction-retry.request_logging', []);
        if (! is_array($config) || RetryToggle::isExplicitlyDisabledValue($config['enabled'] ?? true)) {
            return;
        }

        if (! $this->app->bound('events')) {
            return;
        }

        $this->app->singleton(RequestMonitor::class, static function () use ($config): RequestMonitor {
            return new RequestMonitor($config);
        });

        $events = $this->app['events'];

        $events->listen(QueryExecuted::class, function ($event): void {
            $this->app->make(RequestMonitor::class)->handleQueryExecuted($event);
        });

        if (class_exists(RequestHandled::class)) {
            $events->listen(RequestHandled::class, function ($event): void {
                $this->app->make(RequestMonitor::class)->handleRequestHandled($event);
            });
        }

        if (class_exists(CommandStarting::class)) {
            $events->listen(CommandStarting::class, function ($event): void {
                $this->app->make(RequestMonitor::class)->handleCommandStarting($event);
            });
        }

        if (class_exists(CommandFinished::class)) {
            $events->listen(CommandFinished::class, function ($event): void {
                $this->app->make(RequestMonitor::class)->handleCommandFinished($event);
            });
        }
    }
}
