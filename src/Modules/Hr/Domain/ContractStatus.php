<?php

declare(strict_types=1);

namespace Modules\Hr\Domain;

enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /** Contracts that were (or are) really in force and therefore count for payroll. */
    public function countsForPayroll(): bool
    {
        return $this === self::Active || $this === self::Ended;
    }
}
