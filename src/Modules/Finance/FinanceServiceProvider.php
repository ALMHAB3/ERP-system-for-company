<?php

declare(strict_types=1);

namespace Modules\Finance;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Finance\Application\PostingRuleRegistry;
use Modules\Finance\Application\PostJournalOnDomainEvent;
use Modules\Finance\Application\Rules\CostOfSalesRule;
use Modules\Finance\Application\Rules\CreditNoteRule;
use Modules\Finance\Application\Rules\CustomerReceiptRule;
use Modules\Finance\Application\Rules\GrIrClearingRule;
use Modules\Finance\Application\Rules\InventoryAdjustmentRule;
use Modules\Finance\Application\Rules\InventoryReceiptRule;
use Modules\Finance\Application\Rules\SalesInvoiceRule;
use Modules\Finance\Application\Rules\ShipmentCostSettlementRule;
use Modules\Finance\Application\Rules\SupplierInvoiceRule;
use Modules\Finance\Application\Rules\SupplierPaymentRule;
use Modules\Finance\Domain\JournalRepository;
use Modules\Finance\Infrastructure\DbJournalRepository;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Procurement\Domain\Events\GoodsReceivedClearedToVariance;
use Modules\Procurement\Domain\Events\ShipmentCostSettled;
use Modules\Procurement\Domain\Events\SupplierInvoiceRecorded;
use Modules\Procurement\Domain\Events\SupplierPaid;
use Modules\Sales\Domain\Events\CreditNoteIssued;
use Modules\Sales\Domain\Events\CustomerPaid;
use Modules\Sales\Domain\Events\InvoiceIssued;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JournalRepository::class, DbJournalRepository::class);

        $this->app->tag([
            InventoryReceiptRule::class,
            CostOfSalesRule::class,
            InventoryAdjustmentRule::class,
            SalesInvoiceRule::class,
            SupplierInvoiceRule::class,
            SupplierPaymentRule::class,
            CustomerReceiptRule::class,
            CreditNoteRule::class,
            GrIrClearingRule::class,
            ShipmentCostSettlementRule::class,
        ], 'erp.posting_rules');

        $this->app->singleton(PostingRuleRegistry::class, fn ($app) => new PostingRuleRegistry($app->tagged('erp.posting_rules')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // Finance observes other modules' events; those modules never depend on Finance.
        Event::listen(StockMoved::class, [PostJournalOnDomainEvent::class, 'handle']);
        foreach ([InvoiceIssued::class, SupplierInvoiceRecorded::class, SupplierPaid::class, CustomerPaid::class, CreditNoteIssued::class, GoodsReceivedClearedToVariance::class, ShipmentCostSettled::class] as $event) {
            Event::listen($event, [PostJournalOnDomainEvent::class, 'handle']);
        }
    }
}
