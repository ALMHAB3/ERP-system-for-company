<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Shared\Domain\Fixed;

/** Strategy: one discount rate (0.05 = 5%). The engine takes the single best (highest) applicable rate. */
interface DiscountRule
{
    public function rate(PricingContext $context): ?Fixed;
}
