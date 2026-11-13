<?php

declare(strict_types=1);

namespace Modules\Demo;

use Illuminate\Support\ServiceProvider;
use Modules\Demo\Console\DemoDataCommand;

final class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoDataCommand::class]);
        }
    }
}
