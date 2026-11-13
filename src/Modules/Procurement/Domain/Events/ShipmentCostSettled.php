<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** Final actual cost booked: FX difference and GR/IR variance move into inventory cost and cost of goods sold. */
final readonly class ShipmentCostSettled implements DomainEvent
{
    public function __construct(
        public int $settlementId,
        public string $receiptReference,
        public Fixed $inventoryPart,
        public Fixed $cogsPart,
        /** FX difference on payments (excluding VAT) taken out of the exchange-difference account. */
        public Fixed $fxReclassified,
        /** GR/IR variance previously cleared to purchase variance, taken out of that account. */
        public Fixed $varianceReclassified,
    ) {}
}
