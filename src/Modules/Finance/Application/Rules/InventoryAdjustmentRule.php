<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\MoveType;
use Modules\Shared\Domain\DomainEvent;

/** Stock count gains/losses and write-offs go to the inventory variance account; returns reverse COGS / inventory. */
final class InventoryAdjustmentRule implements PostingRule
{
    private const TYPES = [MoveType::AdjustmentIn, MoveType::AdjustmentOut, MoveType::SalesReturn, MoveType::PurchaseReturn];

    public function handles(DomainEvent $event): bool
    {
        return $event instanceof StockMoved && in_array($event->move->type, self::TYPES, true);
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var StockMoved $event */
        $m = $event->move;
        if ($m->totalCost->isZero()) {
            return null;
        }
        $a = config('erp.accounts');
        $counter = match ($m->type) {
            MoveType::SalesReturn => $a['cost_of_goods_sold'],
            MoveType::PurchaseReturn => $a['goods_received_not_invoiced'],
            default => $a['inventory_variance'],
        };
        $lines = $m->type->isInbound()
            ? [JournalLineData::debit($a['inventory'], $m->totalCost), JournalLineData::credit($counter, $m->totalCost)]
            : [JournalLineData::debit($counter, $m->totalCost), JournalLineData::credit($a['inventory'], $m->totalCost)];

        return new JournalEntryData(
            ucfirst(str_replace('_', ' ', $m->type->value))." {$m->reference}",
            $lines, 'stock_move', (string) $m->id, 'inventory_adjustment', $m->occurredAt,
        );
    }
}
