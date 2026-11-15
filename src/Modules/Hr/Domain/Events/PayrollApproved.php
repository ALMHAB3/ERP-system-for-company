<?php

declare(strict_types=1);

namespace Modules\Hr\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** Payroll approved: books the salary expense and what is owed to employees. */
final readonly class PayrollApproved implements DomainEvent
{
    /** @param array<int,array{expense:Fixed,net:Fixed,advances:Fixed}> $byBranch branch id (0 = head office) => the same three amounts for that branch's employees */
    public function __construct(public int $runId, public string $period, public Fixed $expense, public Fixed $net, public Fixed $advances, public array $byBranch = []) {}
}
