<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class PurchaseOrderLine
{
    public function __construct(
        public ?int $id,
        public int $productId,
        public Fixed $quantity,
        public Fixed $unitPrice,
    ) {}

    public function total(): Fixed
    {
        return $this->quantity->mul($this->unitPrice);
    }
}
