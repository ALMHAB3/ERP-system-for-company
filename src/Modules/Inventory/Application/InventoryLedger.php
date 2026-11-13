<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use DateTimeImmutable;
use Modules\Inventory\Domain\Events\StockMoved;
use Modules\Inventory\Domain\Exceptions\InsufficientStock;
use Modules\Inventory\Domain\Exceptions\InvalidStockMove;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockBalance;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockMove;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Application\EventBus;
use Modules\Shared\Application\TransactionRunner;
use Modules\Shared\Domain\Fixed;

/**
 * The ONLY writer of stock quantities. Balances are never edited directly: every change is
 * an appended ledger line and the balance is the cumulative result of those lines, updated
 * in the same transaction under a row lock on the (product, warehouse) balance.
 * Valuation is moving-average cost.
 */
final class InventoryLedger
{
    public function __construct(
        private readonly StockLedgerRepository $ledger,
        private readonly TransactionRunner $tx,
        private readonly EventBus $events,
    ) {}

    public function record(StockMoveData $data): StockMove
    {
        if (! $data->quantity->isPositive()) {
            throw new InvalidStockMove('Quantity must be positive; direction comes from the move type.');
        }

        return $this->tx->run(function () use ($data): StockMove {
            if ($data->idempotencyKey !== null && ($existing = $this->ledger->findByIdempotencyKey($data->idempotencyKey))) {
                return $existing;
            }

            $balance = $this->ledger->lockBalance($data->productId, $data->warehouseId);

            [$unitCost, $totalCost, $updated] = $data->type->isInbound()
                ? $this->applyInbound($data, $balance)
                : $this->applyOutbound($data, $balance);

            $move = $this->ledger->append(new StockMove(
                id: null,
                productId: $data->productId,
                warehouseId: $data->warehouseId,
                type: $data->type,
                quantity: $data->quantity,
                unitCost: $unitCost,
                totalCost: $totalCost,
                balanceQtyAfter: $updated->onHand,
                balanceValueAfter: $updated->value,
                reference: $data->reference,
                lotNo: $data->lotNo,
                idempotencyKey: $data->idempotencyKey,
                occurredAt: $data->occurredAt ?? new DateTimeImmutable,
            ));
            $this->ledger->saveBalance($data->productId, $data->warehouseId, $updated);

            $this->events->dispatch(new StockMoved($move));

            return $move;
        });
    }

    /**
     * Moves stock between warehouses at cost: one outbound and one inbound line, atomically.
     *
     * @return array{0: StockMove, 1: StockMove} [out, in]
     */
    public function transfer(int $productId, int $fromWarehouseId, int $toWarehouseId, Fixed $quantity, string $reference): array
    {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidStockMove('Source and destination warehouse must differ.');
        }

        return $this->tx->run(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $reference): array {
            $out = $this->record(new StockMoveData($productId, $fromWarehouseId, MoveType::TransferOut, $quantity, null, $reference));
            $in = $this->record(new StockMoveData(
                $productId, $toWarehouseId, MoveType::TransferIn, $quantity, $out->unitCost, $reference,
                totalCost: $out->totalCost,
            ));

            return [$out, $in];
        });
    }

    /** @return array{0: Fixed, 1: Fixed, 2: StockBalance} */
    private function applyInbound(StockMoveData $data, StockBalance $balance): array
    {
        if ($data->totalCost !== null) {
            $total = $data->totalCost;
            $unit = $total->div($data->quantity);
        } elseif ($data->unitCost !== null) {
            $unit = $data->unitCost;
            $total = $data->quantity->mul($unit);
        } else {
            throw new InvalidStockMove('Inbound moves require a unit cost or a total cost.');
        }
        if ($total->isNegative()) {
            throw new InvalidStockMove('Inbound cost cannot be negative.');
        }

        return [$unit, $total, $balance->with(
            onHand: $balance->onHand->add($data->quantity),
            value: $balance->value->add($total),
        )];
    }

    /** @return array{0: Fixed, 1: Fixed, 2: StockBalance} */
    private function applyOutbound(StockMoveData $data, StockBalance $balance): array
    {
        // Reservations already consumed by the caller are no longer counted in `reserved`.
        if ($data->quantity->gt($balance->available())) {
            throw InsufficientStock::for($data->productId, $data->warehouseId, $data->quantity, $balance->available());
        }

        $total = $data->quantity->equals($balance->onHand)
            ? $balance->value // draining the last unit empties the value exactly, no rounding residue
            : Fixed::units(Fixed::mulDiv($balance->value->units, $data->quantity->units, $balance->onHand->units));
        $unit = $total->div($data->quantity);

        return [$unit, $total, $balance->with(
            onHand: $balance->onHand->sub($data->quantity),
            value: $balance->value->sub($total),
        )];
    }
}
