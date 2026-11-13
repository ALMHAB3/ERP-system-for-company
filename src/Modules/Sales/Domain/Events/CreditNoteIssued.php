<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

final readonly class CreditNoteIssued implements DomainEvent
{
    public function __construct(public int $creditNoteId, public string $number, public int $invoiceId, public Fixed $net, public Fixed $tax, public Fixed $gross) {}
}
