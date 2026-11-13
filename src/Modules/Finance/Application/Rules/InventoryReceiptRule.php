<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\MoveType;
use Modules\Shared\Domain\DomainEvent;

/** Goods received: Dr Inventory / Cr Goods-Received-Not-Invoiced (cleared by the supplier invoice). */
final class InventoryReceiptRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof StockMoved && $event->move->type === MoveType::Receipt;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var StockMoved $event */
        $m = $event->move;
        if ($m->totalCost->isZero()) {
            return null;
        }
        $accounts = config('erp.accounts');

        return new JournalEntryData(
            "Goods receipt {$m->reference}",
            [
                JournalLineData::debit($accounts['inventory'], $m->totalCost, "product {$m->productId}"),
                JournalLineData::credit($accounts['goods_received_not_invoiced'], $m->totalCost),
            ],
            'stock_move', (string) $m->id, 'inventory_receipt', $m->occurredAt,
        );
    }
}
