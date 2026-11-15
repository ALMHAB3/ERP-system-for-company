<?php

declare(strict_types=1);

namespace Modules\Hr\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** Salaries paid out of the till or a bank account. */
final readonly class PayrollPaid implements DomainEvent
{
    /** @param array<int,Fixed> $netByBranch branch id (0 = head office) => net salaries of that branch's employees */
    public function __construct(public int $runId, public string $period, public Fixed $amount, public string $cashAccount, public array $netByBranch = []) {}
}
