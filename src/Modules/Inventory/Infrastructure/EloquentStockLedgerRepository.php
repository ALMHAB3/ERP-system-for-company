<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockBalance;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockMove;
use Modules\Shared\Domain\Fixed;
use stdClass;

/** Query-builder implementation (explicit SQL, no model events, no mass-assignment surface). */
final class EloquentStockLedgerRepository implements StockLedgerRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function lockBalance(int $productId, int $warehouseId): StockBalance
    {
        $this->db->table('stock_balances')->insertOrIgnore([
            'product_id' => $productId, 'warehouse_id' => $warehouseId,
            'on_hand' => 0, 'reserved' => 0, 'value' => 0, 'updated_at' => now(),
        ]);

        $row = $this->db->table('stock_balances')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->lockForUpdate()->first();

        return $this->hydrateBalance($row);
    }

    public function balance(int $productId, int $warehouseId): StockBalance
    {
        $row = $this->db->table('stock_balances')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)->first();

        return $row ? $this->hydrateBalance($row) : StockBalance::empty();
    }

    public function saveBalance(int $productId, int $warehouseId, StockBalance $balance): void
    {
        $this->db->table('stock_balances')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->update([
                'on_hand' => $balance->onHand->units,
                'reserved' => $balance->reserved->units,
                'value' => $balance->value->units,
                'updated_at' => now(),
            ]);
    }

    public function append(StockMove $move): StockMove
    {
        $id = $this->db->table('stock_moves')->insertGetId([
            'product_id' => $move->productId,
            'warehouse_id' => $move->warehouseId,
            'type' => $move->type->value,
            'direction' => $move->type->direction(),
            'quantity' => $move->quantity->units,
            'unit_cost' => $move->unitCost->units,
            'total_cost' => $move->totalCost->units,
            'balance_qty_after' => $move->balanceQtyAfter->units,
            'balance_value_after' => $move->balanceValueAfter->units,
            'reference' => $move->reference,
            'lot_no' => $move->lotNo,
            'idempotency_key' => $move->idempotencyKey,
            'occurred_at' => $move->occurredAt->format('Y-m-d H:i:s'),
            'created_at' => now(),
        ]);

        return new StockMove(
            $id, $move->productId, $move->warehouseId, $move->type, $move->quantity, $move->unitCost,
            $move->totalCost, $move->balanceQtyAfter, $move->balanceValueAfter, $move->reference,
            $move->lotNo, $move->idempotencyKey, $move->occurredAt,
        );
    }

    public function findByIdempotencyKey(string $key): ?StockMove
    {
        $row = $this->db->table('stock_moves')->where('idempotency_key', $key)->first();

        return $row ? $this->hydrateMove($row) : null;
    }

    public function movesFor(int $productId, int $warehouseId): iterable
    {
        foreach ($this->db->table('stock_moves')
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->orderBy('id')->cursor() as $row) {
            yield $this->hydrateMove($row);
        }
    }

    public function knownPairs(): iterable
    {
        $pairs = $this->db->table('stock_balances')->select('product_id', 'warehouse_id')
            ->union($this->db->table('stock_moves')->select('product_id', 'warehouse_id'))
            ->get();
        foreach ($pairs as $pair) {
            yield [(int) $pair->product_id, (int) $pair->warehouse_id];
        }
    }

    private function hydrateBalance(stdClass $row): StockBalance
    {
        return new StockBalance(Fixed::units((int) $row->on_hand), Fixed::units((int) $row->reserved), Fixed::units((int) $row->value));
    }

    private function hydrateMove(stdClass $row): StockMove
    {
        return new StockMove(
            (int) $row->id, (int) $row->product_id, (int) $row->warehouse_id, MoveType::from($row->type),
            Fixed::units((int) $row->quantity), Fixed::units((int) $row->unit_cost), Fixed::units((int) $row->total_cost),
            Fixed::units((int) $row->balance_qty_after), Fixed::units((int) $row->balance_value_after),
            $row->reference, $row->lot_no, $row->idempotency_key, new DateTimeImmutable($row->occurred_at),
        );
    }
}
