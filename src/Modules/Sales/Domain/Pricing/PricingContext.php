<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Sales\Domain\CustomerCategory;
use Modules\Shared\Domain\Fixed;

final readonly class PricingContext
{
    public function __construct(
        public int $customerId,
        public CustomerCategory $category,
        public int $productId,
        public int $warehouseId,
        public Fixed $quantity,
    ) {}
}
