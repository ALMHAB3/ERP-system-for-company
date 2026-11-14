<?php

declare(strict_types=1);

namespace Modules\Hr;

use Illuminate\Support\ServiceProvider;

final class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    }
}
