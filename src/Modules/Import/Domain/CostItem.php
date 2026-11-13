<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

/** A shipment-level expense (freight, insurance, port fees...) already expressed in base currency. */
final readonly class CostItem
{
    public function __construct(
        public string $code,
        /** Base-currency amount. */
        public Fixed $amount,
        public CostBasis $basis,
        /** Part of the customs valuation base (CIF): typically freight and insurance. */
        public bool $inCustomsValue = false,
    ) {}
}
