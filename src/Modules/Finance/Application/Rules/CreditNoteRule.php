<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Sales\Domain\Events\CreditNoteIssued;
use Modules\Shared\Domain\DomainEvent;

/** Credit note: Dr Revenue + Dr VAT payable / Cr Receivables. (The stock side is posted by the returned-goods move.) */
final class CreditNoteRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof CreditNoteIssued;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var CreditNoteIssued $event */
        $a = config('erp.accounts');
        $lines = [JournalLineData::debit($a['revenue'], $event->net)];
        if ($event->tax->isPositive()) {
            $lines[] = JournalLineData::debit($a['vat_payable'], $event->tax);
        }
        $lines[] = JournalLineData::credit($a['receivables'], $event->gross, "credit note {$event->number}");

        return new JournalEntryData("Credit note {$event->number}", $lines, 'credit_note', (string) $event->creditNoteId, 'credit_note');
    }
}
