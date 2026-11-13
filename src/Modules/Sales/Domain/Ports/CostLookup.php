<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Ports;

use Modules\Shared\Domain\Fixed;

interface CostLookup
{
    /** Current moving-average unit cost, or null when there is no stock to price against. */
    public function averageCost(int $productId, int $warehouseId): ?Fixed;
}
