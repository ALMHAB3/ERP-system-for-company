<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use DomainException;
use Modules\Sales\Domain\Ports\CostLookup;
use Modules\Shared\Domain\Fixed;

/**
 * Resolves a net unit price: first price source with a price wins (contract > price list),
 * then the best applicable discount, then a margin guard against current cost.
 */
final class PricingEngine
{
    /**
     * @param  list<PriceSource>  $sources  in priority order
     * @param  list<DiscountRule>  $discounts
     */
    public function __construct(
        private readonly array $sources,
        private readonly array $discounts,
        private readonly CostLookup $costs,
        private readonly Fixed $minimumMargin,
    ) {}

    /** True when selling at $unit would fall under cost plus the minimum margin. */
    public function belowFloor(PricingContext $context, Fixed $unit): bool
    {
        $cost = $this->costs->averageCost($context->productId, $context->warehouseId);

        return $cost !== null && $unit->lt($cost->mul(Fixed::of(1)->add($this->minimumMargin)));
    }

    public function quote(PricingContext $context): PriceQuote
    {
        $candidate = null;
        foreach ($this->sources as $source) {
            if ($candidate = $source->resolve($context)) {
                break;
            }
        }
        if ($candidate === null) {
            throw new DomainException("No price found for product {$context->productId} and customer {$context->customerId}.");
        }

        $rate = Fixed::zero();
        foreach ($this->discounts as $discount) {
            $r = $discount->rate($context);
            if ($r !== null && $r->gt($rate)) {
                $rate = $r;
            }
        }

        $unit = $candidate->price->sub($candidate->price->mul($rate));
        $cost = $this->costs->averageCost($context->productId, $context->warehouseId);
        $floor = $cost?->mul(Fixed::of(1)->add($this->minimumMargin));

        return new PriceQuote($candidate->price, $rate, $unit, $candidate->source, $floor !== null && $unit->lt($floor));
    }
}
