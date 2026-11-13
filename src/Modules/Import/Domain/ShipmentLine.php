<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class ShipmentLine
{
    public function __construct(
        public ?int $id,
        public int $productId,
        public Fixed $quantity,
        public Fixed $unitPrice,
        public Fixed $volumeCbm,
        public Fixed $weightKg,
        public Fixed $dutyRate,
        /** Supplier batch number; defaults to SHP-<shipment id> so every shipment is its own lot. */
        public ?string $lotNo = null,
        public ?\DateTimeImmutable $expiryDate = null,
    ) {}

    public function toCostable(): CostableLine
    {
        return new CostableLine($this->id ?? 0, $this->quantity, $this->unitPrice, $this->volumeCbm, $this->weightKg, $this->dutyRate);
    }
}
