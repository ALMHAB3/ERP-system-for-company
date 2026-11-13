<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class SalesOrderLine
{
    public function __construct(
        public ?int $id,
        public int $productId,
        public Fixed $quantity,
        public Fixed $listPrice,
        public Fixed $discountRate,
        public Fixed $unitPrice,
        public ?int $reservationId,
        public ?Fixed $costOfGoods = null,
    ) {}

    public function total(): Fixed
    {
        return $this->quantity->mul($this->unitPrice);
    }
}
