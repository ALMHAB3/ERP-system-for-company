<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Sales\Domain\Events\InvoiceIssued;
use Modules\Shared\Domain\DomainEvent;

/** Sales invoice: Dr Receivables (gross) / Cr Revenue (net) / Cr VAT payable. */
final class SalesInvoiceRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof InvoiceIssued;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var InvoiceIssued $event */
        $a = config('erp.accounts');
        $lines = [
            JournalLineData::debit($a['receivables'], $event->gross, "invoice {$event->number}"),
            JournalLineData::credit($a['revenue'], $event->net),
        ];
        if ($event->tax->isPositive()) {
            $lines[] = JournalLineData::credit($a['vat_payable'], $event->tax);
        }

        return new JournalEntryData("Sales invoice {$event->number}", $lines, 'sales_invoice', (string) $event->invoiceId, 'sales_invoice');
    }
}
