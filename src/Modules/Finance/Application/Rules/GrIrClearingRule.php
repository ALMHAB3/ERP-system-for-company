<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Procurement\Domain\Events\GoodsReceivedClearedToVariance;
use Modules\Shared\Domain\DomainEvent;

/** Residual on GR/IR of a closed receipt goes to purchase price variance (receipts above invoices: Dr GR/IR, Cr variance). */
final class GrIrClearingRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof GoodsReceivedClearedToVariance;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var GoodsReceivedClearedToVariance $event */
        $a = config('erp.accounts');
        $amount = $event->residual->abs();
        $lines = $event->residual->isPositive()
            ? [JournalLineData::debit($a['goods_received_not_invoiced'], $amount), JournalLineData::credit($a['purchase_variance'], $amount)]
            : [JournalLineData::debit($a['purchase_variance'], $amount), JournalLineData::credit($a['goods_received_not_invoiced'], $amount)];

        return new JournalEntryData("GR/IR clearing {$event->receiptReference}: {$event->reason}", $lines, 'grir_clearing', $event->receiptReference, 'grir_clearing');
    }
}
