<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Consumed = 'consumed';
}
