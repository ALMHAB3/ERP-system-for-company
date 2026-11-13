<?php

declare(strict_types=1);

namespace Modules\Import\Infrastructure;

use Modules\Import\Domain\StockReceiver;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Domain\Fixed;

/** Adapter: Import -> Inventory public API. */
final class LedgerStockReceiver implements StockReceiver
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function receive(int $productId, int $warehouseId, Fixed $quantity, Fixed $totalCost, string $reference, string $idempotencyKey): void
    {
        $this->ledger->record(new StockMoveData(
            $productId, $warehouseId, MoveType::Receipt, $quantity, null, $reference,
            totalCost: $totalCost, idempotencyKey: $idempotencyKey,
        ));
    }
}
