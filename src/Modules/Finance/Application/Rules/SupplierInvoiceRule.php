<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Procurement\Domain\Events\SupplierInvoiceRecorded;
use Modules\Shared\Domain\DomainEvent;

/** Supplier invoice: Dr GR/IR (or the expense account) + Dr input VAT / Cr Accounts payable, all at the booking rate. */
final class SupplierInvoiceRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof SupplierInvoiceRecorded;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var SupplierInvoiceRecorded $event */
        $inv = $event->invoice;
        $a = config('erp.accounts');
        $debit = $inv->kind->clearsGoodsReceived() ? $a['goods_received_not_invoiced'] : $inv->expenseAccount;

        $lines = [JournalLineData::debit($debit, $inv->baseSubtotal(), "{$event->supplierName} {$inv->number}")];
        if ($inv->baseTax()->isPositive()) {
            $lines[] = JournalLineData::debit($a['vat_input'], $inv->baseTax());
        }
        $lines[] = JournalLineData::credit($a['payables'], $inv->baseTotal(), $event->supplierName);

        return new JournalEntryData("Supplier invoice {$event->supplierName} {$inv->number} ({$inv->kind->value})", $lines, 'supplier_invoice', (string) $event->invoiceId, 'supplier_invoice');
    }
}
