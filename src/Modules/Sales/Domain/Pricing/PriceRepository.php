<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Sales\Domain\CustomerCategory;
use Modules\Shared\Domain\Fixed;

interface PriceRepository
{
    public function contractPrice(int $customerId, int $productId): ?Fixed;

    /** Category-specific list price, falling back to the default list (category null). */
    public function listPrice(int $productId, CustomerCategory $category): ?Fixed;
}
