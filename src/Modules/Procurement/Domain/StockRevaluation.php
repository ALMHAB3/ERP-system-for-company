<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use Modules\Shared\Domain\Fixed;

/** Port towards the inventory ledger: change the value of one lot without moving quantity. */
interface StockRevaluation
{
    public function revalue(int $productId, int $warehouseId, string $lotNo, Fixed $delta, string $reference, string $idempotencyKey): void;
}
