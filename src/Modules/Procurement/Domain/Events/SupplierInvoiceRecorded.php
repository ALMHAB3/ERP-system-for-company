<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain\Events;

use Modules\Procurement\Domain\SupplierInvoice;
use Modules\Shared\Domain\DomainEvent;

final readonly class SupplierInvoiceRecorded implements DomainEvent
{
    public function __construct(public int $invoiceId, public SupplierInvoice $invoice, public string $supplierName) {}
}
