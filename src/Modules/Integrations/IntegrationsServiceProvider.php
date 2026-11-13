<?php

declare(strict_types=1);

namespace Modules\Integrations;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Integrations\Application\Webhooks;
use Modules\Integrations\Console\DeliverWebhooksCommand;
use Modules\Integrations\Console\IntegrationTokenCommand;

final class IntegrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        foreach (Webhooks::EVENTS as $class) {
            Event::listen($class, fn ($e) => $this->app->make(Webhooks::class)->enqueue($e));
        }
        if ($this->app->runningInConsole()) {
            $this->commands([DeliverWebhooksCommand::class, IntegrationTokenCommand::class]);
        }
    }
}
