<?php

declare(strict_types=1);

namespace Modules\Hr;

use Illuminate\Support\ServiceProvider;
use Modules\Hr\Domain\ContractRepository;
use Modules\Hr\Domain\DepartmentRepository;
use Modules\Hr\Infrastructure\DbContractRepository;
use Modules\Hr\Infrastructure\DbDepartmentRepository;

final class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContractRepository::class, DbContractRepository::class);
        $this->app->bind(DepartmentRepository::class, DbDepartmentRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
