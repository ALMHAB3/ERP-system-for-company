<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Shared\Domain\Fixed;

final class QuantityBreakDiscount implements DiscountRule
{
    /** @param list<array{min_qty: string, rate: string}> $breaks */
    public function __construct(private readonly array $breaks) {}

    public function rate(PricingContext $context): ?Fixed
    {
        $best = null;
        foreach ($this->breaks as $break) {
            if ($context->quantity->gte(Fixed::parse($break['min_qty']))) {
                $rate = Fixed::parse($break['rate']);
                if ($best === null || $rate->gt($best)) {
                    $best = $rate;
                }
            }
        }

        return $best;
    }
}
