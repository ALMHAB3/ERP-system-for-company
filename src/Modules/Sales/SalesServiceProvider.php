<?php

declare(strict_types=1);

namespace Modules\Sales;

use Illuminate\Support\ServiceProvider;
use Modules\Sales\Domain\CustomerRepository;
use Modules\Sales\Domain\Ports\CostLookup;
use Modules\Sales\Domain\Ports\StockAvailability;
use Modules\Sales\Domain\Pricing\PriceRepository;
use Modules\Sales\Domain\Pricing\PricingEngineFactory;
use Modules\Sales\Domain\SalesOrderRepository;
use Modules\Sales\Infrastructure\AtpStockAvailability;
use Modules\Sales\Infrastructure\DbCustomerRepository;
use Modules\Sales\Infrastructure\DbPriceRepository;
use Modules\Sales\Infrastructure\DbSalesOrderRepository;
use Modules\Sales\Infrastructure\LedgerCostLookup;
use Modules\Shared\Domain\Fixed;

final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerRepository::class, DbCustomerRepository::class);
        $this->app->bind(PriceRepository::class, DbPriceRepository::class);
        $this->app->bind(SalesOrderRepository::class, DbSalesOrderRepository::class);
        $this->app->bind(StockAvailability::class, AtpStockAvailability::class);
        $this->app->bind(CostLookup::class, LedgerCostLookup::class);

        $this->app->bind(PricingEngineFactory::class, fn ($app) => new PricingEngineFactory(
            $app->make(PriceRepository::class),
            $app->make(CostLookup::class),
            config('erp.quantity_breaks'),
            Fixed::parse((string) config('erp.min_margin')),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
