<?php

use App\Providers\AppServiceProvider;
use Modules\Audit\AuditServiceProvider;
use Modules\Demo\DemoServiceProvider;
use Modules\Finance\FinanceServiceProvider;
use Modules\Hr\HrServiceProvider;
use Modules\Identity\IdentityServiceProvider;
use Modules\Import\ImportServiceProvider;
use Modules\Integrations\IntegrationsServiceProvider;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Procurement\ProcurementServiceProvider;
use Modules\Reporting\ReportingServiceProvider;
use Modules\Sales\SalesServiceProvider;
use Modules\Shared\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    InventoryServiceProvider::class,
    FinanceServiceProvider::class,
    ImportServiceProvider::class,
    ProcurementServiceProvider::class,
    SalesServiceProvider::class,
    IdentityServiceProvider::class,
    AuditServiceProvider::class,
    ReportingServiceProvider::class,
    HrServiceProvider::class,
    IntegrationsServiceProvider::class,
    DemoServiceProvider::class,
];
