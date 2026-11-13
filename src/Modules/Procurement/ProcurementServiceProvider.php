<?php

declare(strict_types=1);

namespace Modules\Procurement;

use Illuminate\Support\ServiceProvider;
use Modules\Procurement\Domain\ApprovalPolicy;
use Modules\Procurement\Domain\LadderApprovalPolicy;
use Modules\Procurement\Domain\PurchaseOrderRepository;
use Modules\Procurement\Infrastructure\DbPurchaseOrderRepository;

final class ProcurementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PurchaseOrderRepository::class, DbPurchaseOrderRepository::class);
        $this->app->bind(ApprovalPolicy::class, fn () => new LadderApprovalPolicy(config('erp.po_approval_ladder')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
