<?php

declare(strict_types=1);

namespace Modules\Procurement\Infrastructure;

use Modules\Inventory\Application\InventoryLedger;
use Modules\Procurement\Domain\StockRevaluation;
use Modules\Shared\Domain\Fixed;

final class LedgerStockRevaluation implements StockRevaluation
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function revalue(int $productId, int $warehouseId, string $lotNo, Fixed $delta, string $reference, string $idempotencyKey): void
    {
        $this->ledger->revalue($productId, $warehouseId, $lotNo, $delta, $reference, $idempotencyKey);
    }
}
