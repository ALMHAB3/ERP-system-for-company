<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

final class CategoryPriceListSource implements PriceSource
{
    public function __construct(private readonly PriceRepository $prices) {}

    public function resolve(PricingContext $context): ?PriceCandidate
    {
        $price = $this->prices->listPrice($context->productId, $context->category);

        return $price === null ? null : new PriceCandidate($price, 'price_list:'.$context->category->value);
    }
}
