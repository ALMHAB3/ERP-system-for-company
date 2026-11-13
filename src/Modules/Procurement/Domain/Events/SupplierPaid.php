<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

final readonly class SupplierPaid implements DomainEvent
{
    public function __construct(
        public int $paymentId,
        public string $supplierName,
        /** Accounts-payable value being settled, at the rate the invoices were booked. */
        public Fixed $payableBase,
        /** Cash actually leaving, at today's rate. The difference is an exchange gain/loss. */
        public Fixed $cashBase,
        public string $reference,
    ) {}
}
