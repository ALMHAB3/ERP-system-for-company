<?php

declare(strict_types=1);

namespace Modules\Reporting;

use Illuminate\Support\ServiceProvider;
use Modules\Reporting\Domain\DemandForecaster;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DemandForecaster::class);
    }
}
