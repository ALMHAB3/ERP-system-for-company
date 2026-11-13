<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

/** Strategy: one way of finding the list price. The engine uses the first source that has a price. */
interface PriceSource
{
    public function resolve(PricingContext $context): ?PriceCandidate;
}
