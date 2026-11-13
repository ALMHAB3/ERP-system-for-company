<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain;

use DateTimeImmutable;
use Modules\Shared\Domain\Fixed;

final readonly class SupplierInvoice
{
    public function __construct(
        public ?int $id,
        public int $supplierId,
        public string $number,
        public DateTimeImmutable $invoiceDate,
        public DateTimeImmutable $dueDate,
        public string $currency,
        public Fixed $exchangeRate,
        public Fixed $subtotal,
        public Fixed $tax,
        public InvoiceKind $kind,
        /** GR/IR reference this invoice settles: 'shipment:<ref>' or 'po:<number>'. */
        public ?string $receiptReference,
        public ?string $expenseAccount,
        public ?Fixed $paid = null,
        public ?Fixed $basePaid = null,
    ) {}

    public function total(): Fixed
    {
        return $this->subtotal->add($this->tax);
    }

    public function baseSubtotal(): Fixed
    {
        return $this->subtotal->mul($this->exchangeRate);
    }

    public function baseTax(): Fixed
    {
        return $this->tax->mul($this->exchangeRate);
    }

    public function baseTotal(): Fixed
    {
        return $this->baseSubtotal()->add($this->baseTax());
    }

    /** Open amount in the invoice currency. */
    public function balance(): Fixed
    {
        return $this->total()->sub($this->paid ?? Fixed::zero());
    }

    public function baseBalance(): Fixed
    {
        return $this->baseTotal()->sub($this->basePaid ?? Fixed::zero());
    }
}
