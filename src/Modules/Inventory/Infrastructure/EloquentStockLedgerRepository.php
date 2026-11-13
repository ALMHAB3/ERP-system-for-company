<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockBalance;
use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Inventory\Domain\StockLot;
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
            'expiry_date' => $move->expiryDate?->format('Y-m-d'),
            'idempotency_key' => $move->idempotencyKey,
            'occurred_at' => $move->occurredAt->format('Y-m-d H:i:s'),
            'created_at' => now(),
        ]);

        return new StockMove(
            $id, $move->productId, $move->warehouseId, $move->type, $move->quantity, $move->unitCost,
            $move->totalCost, $move->balanceQtyAfter, $move->balanceValueAfter, $move->reference,
            $move->lotNo, $move->idempotencyKey, $move->occurredAt, $move->expiryDate,
        );
    }

    public function movesByIdempotencyKey(string $key): array
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key).'#%';

        return $this->db->table('stock_moves')->whereRaw("idempotency_key like ? escape '\\'", [$like])
            ->orderBy('id')->get()->map(fn ($row) => $this->hydrateMove($row))->all();
    }

    public function lockLots(int $productId, int $warehouseId, ?string $lotNo = null): array
    {
        $query = $this->db->table('stock_lots')->where('product_id', $productId)->where('warehouse_id', $warehouseId);
        if ($lotNo !== null) {
            $query->where('lot_no', $lotNo);
        } else {
            $query->where('on_hand', '>', 0);
        }

        return $query->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('received_at')->orderBy('lot_no')
            ->lockForUpdate()->get()->map(fn ($row) => $this->hydrateLot($row))->all();
    }

    public function saveLot(int $productId, int $warehouseId, StockLot $lot): void
    {
        $exists = $this->db->table('stock_lots')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('lot_no', $lot->lotNo);
        $values = [
            'expiry_date' => $lot->expiryDate?->format('Y-m-d'), 'received_at' => $lot->receivedAt->format('Y-m-d H:i:s'),
            'on_hand' => $lot->onHand->units, 'value' => $lot->value->units,
        ];
        if ($exists->exists()) {
            $this->db->table('stock_lots')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('lot_no', $lot->lotNo)->update($values);
        } else {
            $this->db->table('stock_lots')->insert($values + ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'lot_no' => $lot->lotNo]);
        }
    }

    public function lots(int $productId, int $warehouseId): array
    {
        return $this->db->table('stock_lots')->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->orderBy('lot_no')->get()->map(fn ($row) => $this->hydrateLot($row))->all();
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
            $row->expiry_date ? new DateTimeImmutable($row->expiry_date) : null,
        );
    }

    private function hydrateLot(stdClass $row): StockLot
    {
        return new StockLot(
            $row->lot_no, $row->expiry_date ? new DateTimeImmutable($row->expiry_date) : null,
            new DateTimeImmutable($row->received_at), Fixed::units((int) $row->on_hand), Fixed::units((int) $row->value),
        );
    }
}
