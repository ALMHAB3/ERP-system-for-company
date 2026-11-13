<?php

declare(strict_types=1);

namespace Modules\Procurement\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** The unmatched remainder on GR/IR of a closed receipt moved to price variance (positive: receipts exceeded invoices). */
final readonly class GoodsReceivedClearedToVariance implements DomainEvent
{
    public function __construct(public string $receiptReference, public Fixed $residual, public string $reason) {}
}
