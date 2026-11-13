<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

/** One immutable line of the inventory ledger. */
final readonly class StockMove
{
    public function __construct(
        public ?int $id,
        public int $productId,
        public int $warehouseId,
        public MoveType $type,
        public Fixed $quantity,
        public Fixed $unitCost,
        public Fixed $totalCost,
        public Fixed $balanceQtyAfter,
        public Fixed $balanceValueAfter,
        public string $reference,
        public ?string $lotNo,
        public ?string $idempotencyKey,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $expiryDate = null,
    ) {}

    public function signedQuantity(): Fixed
    {
        return $this->type->isInbound() ? $this->quantity : $this->quantity->neg();
    }

    public function signedValue(): Fixed
    {
        return $this->type->isInbound() ? $this->totalCost : $this->totalCost->neg();
    }
}
