<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use Carbon\CarbonImmutable;
use LogicException;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\Exceptions\InvalidStockMove;
use Modules\Inventory\Domain\IssuedStock;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockBalance;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockLot;
use Modules\Inventory\Domain\StockMove;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * The ONLY writer of stock quantities. Balances are never edited directly: every change is an
 * appended ledger line and the balance is the cumulative result of those lines, updated in the same
 * transaction under row locks on the (product, warehouse) balance and the lots touched.
 *
 * Costing is by LOT: each lot carries the landed cost it arrived with, and issues consume lots
 * FEFO (earliest expiry first), so COGS equals the true cost of the batch actually shipped.
 */
final class InventoryLedger
{
    public function __construct(
        private readonly StockLedgerRepository $ledger,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    /** Single-line convenience; use recordMany() when an outbound may span several lots. */
    public function record(StockMoveData $data): StockMove
    {
        $moves = $this->recordMany($data);
        if (count($moves) !== 1) {
            throw new LogicException('This movement spans several lots; use recordMany().');
        }

        return $moves[0];
    }

    /** @return list<StockMove> one per lot touched (inbound: always one) */
    public function recordMany(StockMoveData $data): array
    {
        if (! $data->quantity->isPositive()) {
            throw new InvalidStockMove('Quantity must be positive; direction comes from the move type.');
        }

        return $this->tx->run(function () use ($data): array {
            if ($data->idempotencyKey !== null && ($existing = $this->ledger->movesByIdempotencyKey($data->idempotencyKey)) !== []) {
                return $existing;
            }

            $balance = $this->ledger->lockBalance($data->productId, $data->warehouseId);
            $plan = $data->type->isInbound() ? [$this->planInbound($data)] : $this->planOutbound($data, $balance);

            $moves = [];
            foreach ($plan as $i => [$lot, $quantity, $total]) {
                $inbound = $data->type->isInbound();
                $balance = $balance->with(
                    onHand: $inbound ? $balance->onHand->add($quantity) : $balance->onHand->sub($quantity),
                    value: $inbound ? $balance->value->add($total) : $balance->value->sub($total),
                );
                $this->ledger->saveLot($data->productId, $data->warehouseId, $lot);
                $moves[] = $this->ledger->append(new StockMove(
                    id: null,
                    productId: $data->productId,
                    warehouseId: $data->warehouseId,
                    type: $data->type,
                    quantity: $quantity,
                    unitCost: $total->div($quantity),
                    totalCost: $total,
                    balanceQtyAfter: $balance->onHand,
                    balanceValueAfter: $balance->value,
                    reference: $data->reference,
                    lotNo: $lot->lotNo,
                    idempotencyKey: $data->idempotencyKey === null ? null : $data->idempotencyKey.'#'.$i,
                    occurredAt: $data->occurredAt ?? CarbonImmutable::now(),
                    expiryDate: $lot->expiryDate,
                ));
            }
            $this->ledger->saveBalance($data->productId, $data->warehouseId, $balance);

            foreach ($moves as $move) {
                $this->events->dispatch(new StockMoved($move));
            }

            return $moves;
        });
    }

    /**
     * Changes the VALUE of one lot without moving any quantity (quantity 0 ledger line). Used by the final-cost settlement
     * once the real cost of a shipment is known. Returns null when the delta is zero.
     */
    public function revalue(int $productId, int $warehouseId, string $lotNo, Fixed $delta, string $reference, string $idempotencyKey): ?StockMove
    {
        if ($delta->isZero()) {
            return null;
        }

        return $this->tx->run(function () use ($productId, $warehouseId, $lotNo, $delta, $reference, $idempotencyKey): ?StockMove {
            if (($existing = $this->ledger->movesByIdempotencyKey($idempotencyKey)) !== []) {
                return $existing[0];
            }
            $balance = $this->ledger->lockBalance($productId, $warehouseId);
            $lot = $this->ledger->lockLots($productId, $warehouseId, $lotNo)[0] ?? throw new InvalidStockMove("Lot {$lotNo} does not exist in warehouse {$warehouseId}.");
            $newLotValue = $lot->value->add($delta);
            $newBalanceValue = $balance->value->add($delta);
            if ($newLotValue->isNegative() || $newBalanceValue->isNegative()) {
                throw new InvalidStockMove("Revaluation of lot {$lotNo} would make its value negative.");
            }

            $this->ledger->saveLot($productId, $warehouseId, $lot->with($lot->onHand, $newLotValue));
            $this->ledger->saveBalance($productId, $warehouseId, $balance->with(value: $newBalanceValue));
            $move = $this->ledger->append(new StockMove(
                null, $productId, $warehouseId, $delta->isPositive() ? MoveType::RevaluationUp : MoveType::RevaluationDown,
                Fixed::zero(), Fixed::zero(), $delta->abs(), $balance->onHand, $newBalanceValue, $reference, $lotNo,
                $idempotencyKey.'#0', CarbonImmutable::now(), $lot->expiryDate,
            ));
            $this->events->dispatch(new StockMoved($move));

            return $move;
        });
    }

    /** Outbound movement helper returning the per-lot result. */
    public function issue(StockMoveData $data): IssuedStock
    {
        if ($data->type->isInbound()) {
            throw new InvalidStockMove('issue() is for outbound movements.');
        }

        return new IssuedStock($this->recordMany($data));
    }

    /**
     * Moves stock between warehouses at lot cost: each lot leaves the source and arrives in the
     * destination with the same lot number, expiry and exact value.
     *
     * @return array{0: list<StockMove>, 1: list<StockMove>} [out, in]
     */
    public function transfer(int $productId, int $fromWarehouseId, int $toWarehouseId, Fixed $quantity, string $reference, ?string $lotNo = null): array
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidStockMove('Source and destination warehouse must differ.');
        }

        return $this->tx->run(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $lotNo): array {
            $out = $this->recordMany(new StockMoveData($productId, $fromWarehouseId, MoveType::TransferOut, $quantity, null, $reference, lotNo: $lotNo));
            $in = [];
            foreach ($out as $leg) {
                $in[] = $this->record(new StockMoveData(
                    $productId, $toWarehouseId, MoveType::TransferIn, $leg->quantity, null, $reference,
                    lotNo: $leg->lotNo, totalCost: $leg->totalCost, expiryDate: $leg->expiryDate,
                ));
            }

            return [$out, $in];
        });
    }

    /** @return array{0: StockLot, 1: Fixed, 2: Fixed} [lot after, quantity, total cost] */
    private function planInbound(StockMoveData $data): array
    {
        if ($data->totalCost !== null) {
            $total = $data->totalCost;
        } elseif ($data->unitCost !== null) {
            $total = $data->quantity->mul($data->unitCost);
        } else {
            throw new InvalidStockMove('Inbound moves require a unit cost or a total cost.');
        }
        if ($total->isNegative()) {
            throw new InvalidStockMove('Inbound cost cannot be negative.');
        }

        $lotNo = $data->lotNo ?? StockLot::DEFAULT;
        $lot = $this->ledger->lockLots($data->productId, $data->warehouseId, $lotNo)[0] ?? null;
        if ($lot !== null && $data->expiryDate !== null && $lot->onHand->isPositive()
            && $lot->expiryDate?->format('Y-m-d') !== $data->expiryDate->format('Y-m-d')) {
            throw new InvalidStockMove("Lot {$lotNo} already holds stock with a different expiry date.");
        }
        $lot = $lot === null
            ? new StockLot($lotNo, $data->expiryDate, $data->occurredAt ?? CarbonImmutable::now(), $data->quantity, $total)
            : new StockLot($lot->lotNo, $data->expiryDate ?? $lot->expiryDate, $lot->receivedAt, $lot->onHand->add($data->quantity), $lot->value->add($total));

        return [$lot, $data->quantity, $total];
    }

    /** @return list<array{0: StockLot, 1: Fixed, 2: Fixed}> */
    private function planOutbound(StockMoveData $data, StockBalance $balance): array
    {
        if ($data->quantity->gt($balance->available())) {
            throw InsufficientStock::for($data->productId, $data->warehouseId, $data->quantity, $balance->available());
        }

        $today = CarbonImmutable::today();
        $lots = $this->ledger->lockLots($data->productId, $data->warehouseId, $data->lotNo);
        if ($data->type === MoveType::Issue) {   // sales never ship expired goods; write-offs and counts may take any lot
            $lots = array_values(array_filter($lots, static fn (StockLot $l) => ! $l->isExpired($today)));
        }
        $usable = array_reduce($lots, static fn (Fixed $c, StockLot $l) => $c->add($l->onHand), Fixed::zero());
        if ($data->quantity->gt($usable)) {
            throw InsufficientStock::for($data->productId, $data->warehouseId, $data->quantity, $usable);
        }

        $plan = [];
        $remaining = $data->quantity;
        foreach ($lots as $lot) {
            if (! $remaining->isPositive()) {
                break;
            }
            if (! $lot->onHand->isPositive()) {
                continue;
            }
            $take = $remaining->lt($lot->onHand) ? $remaining : $lot->onHand;
            $cost = $take->equals($lot->onHand)
                ? $lot->value   // draining a lot empties its value exactly, no rounding residue
                : Fixed::units(Fixed::mulDiv($lot->value->units, $take->units, $lot->onHand->units));
            $plan[] = [$lot->with($lot->onHand->sub($take), $lot->value->sub($cost)), $take, $cost];
            $remaining = $remaining->sub($take);
        }

        return $plan;
    }
}
