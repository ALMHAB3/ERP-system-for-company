<?php

declare(strict_types=1);

namespace Modules\Hr\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** Salaries paid out of the till or a bank account. */
final readonly class PayrollPaid implements DomainEvent
{
    public function __construct(public int $runId, public string $period, public Fixed $amount, public string $cashAccount) {}
}
