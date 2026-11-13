<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Events;

use Modules\Inventory\Domain\StockMove;
use Modules\Shared\Domain\DomainEvent;

final readonly class StockMoved implements DomainEvent
{
    public function __construct(public StockMove $move) {}
}
