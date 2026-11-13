<?php

declare(strict_types=1);

namespace Modules\Finance\Application\Rules;

use Modules\Finance\Domain\JournalEntryData;
use Modules\Finance\Domain\JournalLineData;
use Modules\Finance\Domain\PostingRule;
use Modules\Procurement\Domain\Events\ShipmentCostSettled;
use Modules\Shared\Domain\DomainEvent;

/**
 * Final cost of a shipment: the exchange difference (and any GR/IR variance) booked while paying moves into the cost of
 * the goods. Dr Inventory (still in stock) + Dr COGS (already sold) + Dr/Cr purchase variance / Cr exchange difference.
 * The signed amounts always net to zero.
 */
final class ShipmentCostSettlementRule implements PostingRule
{
    public function handles(DomainEvent $event): bool
    {
        return $event instanceof ShipmentCostSettled;
    }

    public function build(DomainEvent $event): ?JournalEntryData
    {
        /** @var ShipmentCostSettled $event */
        $a = config('erp.accounts');
        // net debit per account (negative = credit)
        $net = [
            $a['inventory'] => $event->inventoryPart,
            $a['cost_of_goods_sold'] => $event->cogsPart,
            $a['purchase_variance'] => $event->varianceReclassified,
            $a['fx_difference'] => $event->fxReclassified->neg(),
        ];
        $lines = [];
        foreach ($net as $account => $amount) {
            if ($amount->isPositive()) {
                $lines[] = JournalLineData::debit((string) $account, $amount, $event->receiptReference);
            } elseif ($amount->isNegative()) {
                $lines[] = JournalLineData::credit((string) $account, $amount->neg(), $event->receiptReference);
            }
        }
        if (count($lines) < 2) {
            return null;
        }

        return new JournalEntryData("Final cost settlement {$event->receiptReference}", $lines, 'shipment_settlement', $event->receiptReference, 'shipment_cost_settlement');
    }
}
