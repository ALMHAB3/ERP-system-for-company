<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Events;

use Modules\Inventory\Domain\Reservation;
use Modules\Shared\Domain\DomainEvent;

final readonly class StockReserved implements DomainEvent
{
    public function __construct(public Reservation $reservation) {}
}
