<?php

declare(strict_types=1);

namespace Modules\Finance;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Finance\Application\PostingRuleRegistry;
use Modules\Finance\Application\PostJournalOnDomainEvent;
use Modules\Finance\Application\Rules\CostOfSalesRule;
use Modules\Finance\Application\Rules\InventoryAdjustmentRule;
use Modules\Finance\Application\Rules\InventoryReceiptRule;
use Modules\Finance\Application\Rules\SalesInvoiceRule;
use Modules\Finance\Domain\JournalRepository;
use Modules\Finance\Infrastructure\DbJournalRepository;
use Modules\Inventory\Domain\Events\StockMoved;
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
        ], 'erp.posting_rules');

        $this->app->singleton(PostingRuleRegistry::class, fn ($app) => new PostingRuleRegistry($app->tagged('erp.posting_rules')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // Finance observes other modules' events; those modules never depend on Finance.
        Event::listen(StockMoved::class, [PostJournalOnDomainEvent::class, 'handle']);
        Event::listen(InvoiceIssued::class, [PostJournalOnDomainEvent::class, 'handle']);
    }
}
