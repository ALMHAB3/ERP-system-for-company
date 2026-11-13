<?php

declare(strict_types=1);

namespace Modules\Shared;

use Illuminate\Support\ServiceProvider;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Infrastructure\DbTransactionRunner;
use Modules\Shared\Infrastructure\LaravelEventBus;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventBus::class, LaravelEventBus::class);
        $this->app->singleton(TransactionRunner::class, DbTransactionRunner::class);
    }
}
