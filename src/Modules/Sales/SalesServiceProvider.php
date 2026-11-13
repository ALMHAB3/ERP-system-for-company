<?php

declare(strict_types=1);

namespace Modules\Sales;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Sales\Application\EInvoiceService;
use Modules\Sales\Console\VerifyInvoicesCommand;
use Modules\Sales\Domain\CustomerRepository;
use Modules\Sales\Domain\Events\InvoiceIssued;
use Modules\Sales\Domain\Ports\CostLookup;
use Modules\Sales\Domain\Ports\SerialTracking;
use Modules\Sales\Domain\Ports\StockAvailability;
use Modules\Sales\Domain\Pricing\PriceRepository;
use Modules\Sales\Domain\Pricing\PricingEngineFactory;
use Modules\Sales\Domain\SalesOrderRepository;
use Modules\Sales\Infrastructure\AtpStockAvailability;
use Modules\Sales\Infrastructure\DbCustomerRepository;
use Modules\Sales\Infrastructure\DbPriceRepository;
use Modules\Sales\Infrastructure\DbSalesOrderRepository;
use Modules\Sales\Infrastructure\InventorySerialTracking;
use Modules\Sales\Infrastructure\LedgerCostLookup;
use Modules\Shared\Domain\Fixed;

final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerRepository::class, DbCustomerRepository::class);
        $this->app->bind(PriceRepository::class, DbPriceRepository::class);
        $this->app->bind(SalesOrderRepository::class, DbSalesOrderRepository::class);
        $this->app->bind(SerialTracking::class, InventorySerialTracking::class);
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
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyInvoicesCommand::class]);
        }
        // Seal the e-invoice inside the issuing transaction (the event is dispatched synchronously).
        Event::listen(InvoiceIssued::class, fn (InvoiceIssued $e) => $this->app->make(EInvoiceService::class)->seal($e->invoiceId));
    }
}
