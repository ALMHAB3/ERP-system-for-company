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
            foreach ($this->ledger->movesFor($productId, $warehouseId) as $move) {
                $qty = $qty->add($move->signedQuantity());
                $value = $value->add($move->signedValue());
                if (! $move->balanceQtyAfter->equals($qty) || ! $move->balanceValueAfter->equals($value)) {
                    $drifts[] = new LedgerDrift($productId, $warehouseId, "running balance at move #{$move->id}", (string) $move->balanceQtyAfter.' / '.$move->balanceValueAfter, "{$qty} / {$value}");
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
