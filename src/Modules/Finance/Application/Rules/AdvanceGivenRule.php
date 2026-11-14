<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Hr\Domain\Events\AdvanceGiven;
use Modules\Shared\Domain\DomainEvent;

/** Dr Employee advances / Cr Cash or bank. */
final class AdvanceGivenRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof AdvanceGiven;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var AdvanceGiven $event */
        return new JournalEntryData("Advance to {$event->employeeName}", [
            JournalLineData::debit(config('erp.accounts.employee_advances'), $event->amount, $event->employeeName),
            JournalLineData::credit($event->cashAccount, $event->amount),
        ], 'employee_advance', (string) $event->advanceId, 'advance_given');
    }
}
