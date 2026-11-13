<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

final readonly class CustomerPaid implements DomainEvent
{
    public function __construct(public int $receiptId, public string $customerName, public Fixed $amount, public string $reference, public ?string $cashAccount = null) {}
}
