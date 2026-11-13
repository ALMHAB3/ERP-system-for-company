<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Sales\Domain\Events\CustomerPaid;
use Modules\Shared\Domain\DomainEvent;

final class CustomerReceiptRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof CustomerPaid;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var CustomerPaid $event */
        $a = config('erp.accounts');

        return new JournalEntryData("Receipt from {$event->customerName} ({$event->reference})", [
            JournalLineData::debit($event->cashAccount ?? $a['bank'], $event->amount, $event->reference),
            JournalLineData::credit($a['receivables'], $event->amount, $event->customerName),
        ], 'customer_receipt', (string) $event->receiptId, 'customer_receipt');
    }
}
