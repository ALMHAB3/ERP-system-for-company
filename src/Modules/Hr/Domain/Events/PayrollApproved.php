<?php

declare(strict_types=1);

namespace Modules\Hr\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** Payroll approved: books the salary expense and what is owed to employees. */
final readonly class PayrollApproved implements DomainEvent
{
    public function __construct(public int $runId, public string $period, public Fixed $expense, public Fixed $net, public Fixed $advances) {}
}
