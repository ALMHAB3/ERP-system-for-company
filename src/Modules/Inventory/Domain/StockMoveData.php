<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

/** Command describing a movement to append to the ledger. Quantity is always positive. */
final readonly class StockMoveData
{
    public function __construct(
        public int $productId,
        public int $warehouseId,
        public MoveType $type,
        public Fixed $quantity,
        /** Required for inbound moves unless $totalCost is given. Outbound moves use the moving average. */
        public ?Fixed $unitCost,
        public string $reference,
        /** Lot to receive into / take from. Null: inbound -> 'NOLOT'; outbound -> FEFO across lots. */
        public ?string $lotNo = null,
        /** Exact inbound value, overrides quantity x unitCost (e.g. landed cost totals, transfers). */
        public ?Fixed $totalCost = null,
        /** Re-submitting the same key returns the original move instead of posting twice. */
        public ?string $idempotencyKey = null,
        public ?DateTimeImmutable $occurredAt = null,
        /** Lot expiry date (inbound only). Issues (sales) never take from an expired lot. */
        public ?DateTimeImmutable $expiryDate = null,
    ) {}
}
