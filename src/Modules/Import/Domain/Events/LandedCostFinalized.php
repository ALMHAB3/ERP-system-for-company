<?php

declare(strict_types=1);

namespace Modules\Import\Domain\Events;

use Modules\Import\Domain\LandedCostResult;
use Modules\Shared\Domain\DomainEvent;

final readonly class LandedCostFinalized implements DomainEvent
{
    public function __construct(public int $shipmentId, public LandedCostResult $result) {}
}
