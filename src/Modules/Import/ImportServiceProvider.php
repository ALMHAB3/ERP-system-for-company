<?php

declare(strict_types=1);

namespace Modules\Import;

use Illuminate\Support\ServiceProvider;
use Modules\Import\Domain\AllocationStrategyFactory;
use Modules\Import\Domain\ShipmentRepository;
use Modules\Import\Domain\StockReceiver;
use Modules\Import\Domain\Strategies\ByQuantity;
use Modules\Import\Domain\Strategies\ByValue;
use Modules\Import\Domain\Strategies\ByVolume;
use Modules\Import\Domain\Strategies\ByWeight;
use Modules\Import\Infrastructure\DbShipmentRepository;
use Modules\Import\Infrastructure\LedgerStockReceiver;

final class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShipmentRepository::class, DbShipmentRepository::class);
        $this->app->bind(StockReceiver::class, LedgerStockReceiver::class);

        $this->app->tag([ByValue::class, ByVolume::class, ByWeight::class, ByQuantity::class], 'erp.allocation_strategies');
        $this->app->singleton(AllocationStrategyFactory::class, fn ($app) => new AllocationStrategyFactory($app->tagged('erp.allocation_strategies')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
