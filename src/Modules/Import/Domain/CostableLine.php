<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

/** One commercial-invoice line of a shipment, with the physical data needed for allocation. */
final readonly class CostableLine
{
    public function __construct(
        public int|string $id,
        public Fixed $quantity,
        /** Unit price in the supplier's invoice currency. */
        public Fixed $unitPrice,
        public Fixed $volumeCbm,
        public Fixed $weightKg,
        /** Customs duty rate for the line's HS code, e.g. 0.0500 = 5%. */
        public Fixed $dutyRate,
    ) {}

    /** Purchase value converted to base currency. */
    public function purchaseValue(Fixed $exchangeRate): Fixed
    {
        return $this->quantity->mul($this->unitPrice)->mul($exchangeRate);
    }
}
