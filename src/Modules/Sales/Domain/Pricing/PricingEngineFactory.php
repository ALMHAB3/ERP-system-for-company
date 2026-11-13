<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Sales\Domain\CustomerCategory;
use Modules\Sales\Domain\Ports\CostLookup;
use Modules\Shared\Domain\Fixed;

/** Factory: assembles the pricing strategies that apply to a customer category. */
final class PricingEngineFactory
{
    /** @param list<array{min_qty: string, rate: string}> $quantityBreaks */
    public function __construct(
        private readonly PriceRepository $prices,
        private readonly CostLookup $costs,
        private readonly array $quantityBreaks,
        private readonly Fixed $minimumMargin,
    ) {}

    public function forCategory(CustomerCategory $category): PricingEngine
    {
        $sources = [new ContractPriceSource($this->prices), new CategoryPriceListSource($this->prices)];
        $discounts = match ($category) {
            CustomerCategory::Wholesale => [new QuantityBreakDiscount($this->quantityBreaks)],
            CustomerCategory::Retail, CustomerCategory::Direct => [],
        };

        return new PricingEngine($sources, $discounts, $this->costs, $this->minimumMargin);
    }
}
