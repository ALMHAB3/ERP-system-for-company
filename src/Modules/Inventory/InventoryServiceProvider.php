<?php

declare(strict_types=1);

namespace Modules\Inventory;

use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Console\ReconcileLedgerCommand;
use Modules\Inventory\Domain\ReservationRepository;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Infrastructure\EloquentReservationRepository;
use Modules\Inventory\Infrastructure\EloquentStockLedgerRepository;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockLedgerRepository::class, EloquentStockLedgerRepository::class);
        $this->app->bind(ReservationRepository::class, EloquentReservationRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileLedgerCommand::class]);
        }
    }
}
