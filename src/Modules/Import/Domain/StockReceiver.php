<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

/** Port towards the inventory ledger (implemented by an adapter, keeping Import decoupled from Inventory internals). */
interface StockReceiver
{
    public function receive(
        int $productId,
        int $warehouseId,
        Fixed $quantity,
        Fixed $totalCost,
        string $reference,
        string $idempotencyKey,
        string $lotNo,
        ?\DateTimeImmutable $expiryDate = null,
    ): void;
}
