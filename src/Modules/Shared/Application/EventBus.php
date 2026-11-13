<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

use Modules\Shared\Domain\DomainEvent;

interface EventBus
{
    /** Dispatches synchronously, inside the caller's transaction, so postings stay atomic. */
    public function dispatch(DomainEvent $event): void;
}
