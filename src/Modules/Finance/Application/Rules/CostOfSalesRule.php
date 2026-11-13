<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\MoveType;
use Modules\Shared\Domain\DomainEvent;

/** Goods issued to a customer: Dr COGS / Cr Inventory at the moving-average cost of the batch actually shipped. */
final class CostOfSalesRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof StockMoved && $event->move->type === MoveType::Issue;
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
            "Cost of goods sold {$m->reference}",
            [
                JournalLineData::debit($accounts['cost_of_goods_sold'], $m->totalCost, "product {$m->productId}"),
                JournalLineData::credit($accounts['inventory'], $m->totalCost),
            ],
            'stock_move', (string) $m->id, 'cost_of_sales', $m->occurredAt,
        );
    }
}
