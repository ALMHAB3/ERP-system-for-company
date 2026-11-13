<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class PurchaseOrder
{
    /** @param list<PurchaseOrderLine> $lines */
    public function __construct(
        public ?int $id,
        public string $number,
        public string $supplier,
        public string $currency,
        public Fixed $exchangeRate,
        public PurchaseOrderStatus $status,
        public int $requiredApprovalLevel,
        public array $lines,
    ) {}

    /** Order total in base currency. */
    public function total(): Fixed
    {
        $sum = Fixed::zero();
        foreach ($this->lines as $line) {
            $sum = $sum->add($line->total()->mul($this->exchangeRate));
        }

        return $sum;
    }

    public function withStatus(PurchaseOrderStatus $status): self
    {
        return new self($this->id, $this->number, $this->supplier, $this->currency, $this->exchangeRate, $status, $this->requiredApprovalLevel, $this->lines);
    }
}
