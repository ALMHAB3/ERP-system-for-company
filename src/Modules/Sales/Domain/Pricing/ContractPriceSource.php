<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

final class ContractPriceSource implements PriceSource
{
    public function __construct(private readonly PriceRepository $prices) {}

    public function resolve(PricingContext $context): ?PriceCandidate
    {
        $price = $this->prices->contractPrice($context->customerId, $context->productId);

        return $price === null ? null : new PriceCandidate($price, 'contract');
    }
}
