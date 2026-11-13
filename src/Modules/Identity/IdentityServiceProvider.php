<?php

declare(strict_types=1);

namespace Modules\Identity;

use Illuminate\Support\ServiceProvider;
use Modules\Identity\Application\AccessControl;
use Modules\Identity\Console\CreateAdminCommand;
use Modules\Identity\Domain\AccessRepository;
use Modules\Identity\Infrastructure\DbAccessRepository;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccessRepository::class, DbAccessRepository::class);
        $this->app->scoped(AccessControl::class); // fresh per request / queued job
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([CreateAdminCommand::class]);
        }
    }
}
