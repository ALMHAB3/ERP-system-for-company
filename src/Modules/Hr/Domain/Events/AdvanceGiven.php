<?php

declare(strict_types=1);

namespace Modules\Hr\Domain\Events;

use Modules\Shared\Domain\DomainEvent;
use Modules\Shared\Domain\Fixed;

/** An advance handed to an employee (recovered later from salary). */
final readonly class AdvanceGiven implements DomainEvent
{
    public function __construct(public int $advanceId, public string $employeeName, public Fixed $amount, public string $cashAccount) {}
}
