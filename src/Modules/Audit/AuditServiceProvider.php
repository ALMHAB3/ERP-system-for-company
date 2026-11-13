<?php

declare(strict_types=1);

namespace Modules\Audit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Audit\Application\AuditContext;
use Modules\Audit\Application\AuditLogger;
use Modules\Audit\Application\RecordDomainEvents;
use Modules\Audit\Console\VerifyAuditChainCommand;
use Modules\Audit\Infrastructure\ChainedAuditLogger;
use Modules\Import\Domain\Events\LandedCostFinalized;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\Events\StockReserved;
use Modules\Sales\Domain\Events\InvoiceIssued;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditContext::class);
        $this->app->bind(AuditLogger::class, ChainedAuditLogger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        foreach ([StockMoved::class, StockReserved::class, LandedCostFinalized::class, InvoiceIssued::class] as $event) {
            Event::listen($event, [RecordDomainEvents::class, 'handle']);
        }
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditChainCommand::class]);
        }
    }
}
