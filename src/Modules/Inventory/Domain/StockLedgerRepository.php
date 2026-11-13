<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain;

interface StockLedgerRepository
{
    /** Returns the balance row (created empty if missing) holding a row-level write lock until commit. */
    public function lockBalance(int $productId, int $warehouseId): StockBalance;

    public function balance(int $productId, int $warehouseId): StockBalance;

    public function saveBalance(int $productId, int $warehouseId, StockBalance $balance): void;

    public function append(StockMove $move): StockMove;

    /** @return list<StockMove> moves previously posted under this idempotency key (empty if none) */
    public function movesByIdempotencyKey(string $key): array;

    /**
     * Locks and returns lots in FEFO order (earliest expiry first, no-expiry last, then oldest).
     * With $lotNo, only that lot (even if empty). Without, only lots holding stock.
     *
     * @return list<StockLot>
     */
    public function lockLots(int $productId, int $warehouseId, ?string $lotNo = null): array;

    public function saveLot(int $productId, int $warehouseId, StockLot $lot): void;

    /** @return list<StockLot> every lot row, including empty ones */
    public function lots(int $productId, int $warehouseId): array;

    /** @return iterable<StockMove> in ledger order */
    public function movesFor(int $productId, int $warehouseId): iterable;

    /** @return iterable<array{0:int,1:int}> [productId, warehouseId] pairs that have a balance row or moves */
    public function knownPairs(): iterable;
}
