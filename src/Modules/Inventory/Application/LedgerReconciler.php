<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use Modules\Inventory\Domain\LedgerDrift;
use Modules\Inventory\Domain\ReservationRepository;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Shared\Domain\Fixed;

/** Proves the stored balances equal what the ledger lines add up to. Any drift means tampering or a bug. */
final class LedgerReconciler
{
    public function __construct(
        private readonly StockLedgerRepository $ledger,
        private readonly ReservationRepository $reservations,
    ) {}

    /** @return list<LedgerDrift> */
    public function reconcile(): array
    {
        $drifts = [];
        foreach ($this->ledger->knownPairs() as [$productId, $warehouseId]) {
            $qty = Fixed::zero();
            $value = Fixed::zero();
            $perLot = [];
            foreach ($this->ledger->movesFor($productId, $warehouseId) as $move) {
                $qty = $qty->add($move->signedQuantity());
                $value = $value->add($move->signedValue());
                $lot = $move->lotNo ?? 'NOLOT';
                $perLot[$lot] = [($perLot[$lot][0] ?? Fixed::zero())->add($move->signedQuantity()), ($perLot[$lot][1] ?? Fixed::zero())->add($move->signedValue())];
                if (! $move->balanceQtyAfter->equals($qty) || ! $move->balanceValueAfter->equals($value)) {
                    $drifts[] = new LedgerDrift($productId, $warehouseId, "running balance at move #{$move->id}", (string) $move->balanceQtyAfter.' / '.$move->balanceValueAfter, "{$qty} / {$value}");
                }
            }

            $storedLots = [];
            foreach ($this->ledger->lots($productId, $warehouseId) as $lot) {
                $storedLots[$lot->lotNo] = $lot;
            }
            foreach (array_unique([...array_keys($perLot), ...array_keys($storedLots)]) as $lotNo) {
                $expectedQty = $perLot[$lotNo][0] ?? Fixed::zero();
                $expectedValue = $perLot[$lotNo][1] ?? Fixed::zero();
                $lot = $storedLots[$lotNo] ?? null;
                if ($lot === null || ! $lot->onHand->equals($expectedQty) || ! $lot->value->equals($expectedValue)) {
                    $drifts[] = new LedgerDrift($productId, $warehouseId, "lot {$lotNo}", $lot === null ? 'missing' : $lot->onHand.' / '.$lot->value, "{$expectedQty} / {$expectedValue}");
                }
            }

            $stored = $this->ledger->balance($productId, $warehouseId);
            if (! $stored->onHand->equals($qty)) {
                $drifts[] = new LedgerDrift($productId, $warehouseId, 'on_hand', (string) $stored->onHand, (string) $qty);
            }
            if (! $stored->value->equals($value)) {
                $drifts[] = new LedgerDrift($productId, $warehouseId, 'value', (string) $stored->value, (string) $value);
            }
            $reserved = $this->reservations->activeTotal($productId, $warehouseId);
            if (! $stored->reserved->equals($reserved)) {
                $drifts[] = new LedgerDrift($productId, $warehouseId, 'reserved', (string) $stored->reserved, (string) $reserved);
            }
        }

        return $drifts;
    }
}
