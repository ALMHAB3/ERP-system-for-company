<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use DateTimeImmutable;
use Modules\Inventory\Domain\Events\StockReserved;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\Exceptions\InvalidStockMove;
use Modules\Inventory\Domain\Exceptions\ReservationNotActive;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\Reservation;
use Modules\Inventory\Domain\ReservationRepository;
use Modules\Inventory\Domain\ReservationStatus;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockMove;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * Available-to-Promise: instant stock reservation. The balance row is locked
 * (SELECT ... FOR UPDATE) for the whole check-and-reserve, so two concurrent
 * orders can never both reserve the last units. Lock order is always
 * reservation row first, balance row second, to avoid deadlocks.
 */
final class AtpService
{
    public function __construct(
        private readonly StockLedgerRepository $ledger,
        private readonly ReservationRepository $reservations,
        private readonly InventoryLedger $inventory,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    public function availableToPromise(int $productId, int $warehouseId): Fixed
    {
        return $this->ledger->balance($productId, $warehouseId)->available();
    }

    public function reserve(
        int $productId,
        int $warehouseId,
        Fixed $quantity,
        string $reference,
        ?DateTimeImmutable $expiresAt = null,
    ): Reservation {
        if (! $quantity->isPositive()) {
            throw new InvalidStockMove('Reservation quantity must be positive.');
        }

        return $this->tx->run(function () use ($productId, $warehouseId, $quantity, $reference, $expiresAt): Reservation {
            $balance = $this->ledger->lockBalance($productId, $warehouseId);
            if ($quantity->gt($balance->available())) {
                throw InsufficientStock::for($productId, $warehouseId, $quantity, $balance->available());
            }

            $this->ledger->saveBalance($productId, $warehouseId, $balance->with(reserved: $balance->reserved->add($quantity)));
            $reservation = $this->reservations->create(new Reservation(
                null, $productId, $warehouseId, $quantity, $reference, ReservationStatus::Active, $expiresAt,
            ));
            $this->events->dispatch(new StockReserved($reservation));

            return $reservation;
        });
    }

    public function release(int $reservationId): void
    {
        $this->tx->run(function () use ($reservationId): void {
            $reservation = $this->activeOrFail($reservationId);
            $balance = $this->ledger->lockBalance($reservation->productId, $reservation->warehouseId);
            $this->ledger->saveBalance(
                $reservation->productId,
                $reservation->warehouseId,
                $balance->with(reserved: $balance->reserved->sub($reservation->quantity)),
            );
            $this->reservations->setStatus($reservationId, ReservationStatus::Released);
        });
    }

    /** Turns a reservation into a real stock issue (cost = moving average at that instant). */
    public function consume(int $reservationId, string $reference, ?string $idempotencyKey = null): StockMove
    {
        return $this->tx->run(function () use ($reservationId, $reference, $idempotencyKey): StockMove {
            $reservation = $this->activeOrFail($reservationId);
            $balance = $this->ledger->lockBalance($reservation->productId, $reservation->warehouseId);
            $this->ledger->saveBalance(
                $reservation->productId,
                $reservation->warehouseId,
                $balance->with(reserved: $balance->reserved->sub($reservation->quantity)),
            );
            $this->reservations->setStatus($reservationId, ReservationStatus::Consumed);

            return $this->inventory->record(new StockMoveData(
                $reservation->productId,
                $reservation->warehouseId,
                MoveType::Issue,
                $reservation->quantity,
                null,
                $reference,
                idempotencyKey: $idempotencyKey,
            ));
        });
    }

    /** Frees every active reservation whose expiry has passed. Returns how many were released. */
    public function releaseExpired(DateTimeImmutable $now): int
    {
        $released = 0;
        foreach ($this->reservations->expiredActiveIds($now) as $id) {
            try {
                $this->release($id);
                $released++;
            } catch (ReservationNotActive) {
                // released/consumed concurrently - nothing to do
            }
        }

        return $released;
    }

    private function activeOrFail(int $reservationId): Reservation
    {
        $reservation = $this->reservations->lock($reservationId)
            ?? throw new ReservationNotActive("Reservation {$reservationId} does not exist.");
        if ($reservation->status !== ReservationStatus::Active) {
            throw new ReservationNotActive("Reservation {$reservationId} is {$reservation->status->value}.");
        }

        return $reservation;
    }
}
