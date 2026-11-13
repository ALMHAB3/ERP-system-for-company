<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

final readonly class LedgerDrift
{
    public function __construct(
        public int $productId,
        public int $warehouseId,
        public string $field,
        public string $stored,
        public string $recomputed,
    ) {}

    public function __toString(): string
    {
        return "product={$this->productId} warehouse={$this->warehouseId} {$this->field}: stored={$this->stored} recomputed={$this->recomputed}";
    }
}
