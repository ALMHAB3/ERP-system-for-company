<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Domain\DomainEvent;

final class LaravelEventBus implements EventBus
{
    public function __construct(private readonly Dispatcher $events) {}

    public function dispatch(DomainEvent $event): void
    {
        $this->events->dispatch($event);
    }
}
