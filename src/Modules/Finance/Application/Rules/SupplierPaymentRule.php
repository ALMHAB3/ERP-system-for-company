<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Procurement\Domain\Events\SupplierPaid;
use Modules\Shared\Domain\DomainEvent;

/** Payment: Dr Accounts payable (invoice rate) / Cr Bank (today's rate); the difference is an exchange loss or gain. */
final class SupplierPaymentRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof SupplierPaid;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var SupplierPaid $event */
        $a = config('erp.accounts');
        $lines = [JournalLineData::debit($a['payables'], $event->payableBase, $event->supplierName)];
        $diff = $event->cashBase->sub($event->payableBase);
        if ($diff->isPositive()) {
            $lines[] = JournalLineData::debit($a['fx_difference'], $diff, 'exchange loss');
        } elseif ($diff->isNegative()) {
            $lines[] = JournalLineData::credit($a['fx_difference'], $diff->neg(), 'exchange gain');
        }
        $lines[] = JournalLineData::credit($event->cashAccount ?? $a['bank'], $event->cashBase, $event->reference);

        return new JournalEntryData("Payment to {$event->supplierName} ({$event->reference})", $lines, 'supplier_payment', (string) $event->paymentId, 'supplier_payment');
    }
}
