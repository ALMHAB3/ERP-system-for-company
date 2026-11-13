<?php

declare(strict_types=1);

namespace Modules\Sales\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class SalesOrder
{
    /** @param list<SalesOrderLine> $lines */
    public function __construct(
        public ?int $id,
        public string $number,
        public int $customerId,
        public int $warehouseId,
        public string $status, // reserved | shipped | invoiced | cancelled
        public array $lines,
        public PaymentMethod $paymentMethod = PaymentMethod::Credit,
        public ?string $cashAccount = null,
        public ?string $paymentReference = null,
    ) {}

    public function net(): Fixed
    {
        $sum = Fixed::zero();
        foreach ($this->lines as $line) {
            $sum = $sum->add($line->total());
        }

        return $sum;
    }
}
