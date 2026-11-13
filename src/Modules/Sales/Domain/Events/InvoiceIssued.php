<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

final readonly class InvoiceIssued implements DomainEvent
{
    public function __construct(
        public int $invoiceId,
        public string $number,
        public int $orderId,
        public Fixed $net,
        public Fixed $tax,
        public Fixed $gross,
    ) {}
}
