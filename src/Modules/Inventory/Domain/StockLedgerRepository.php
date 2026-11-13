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

    public function findByIdempotencyKey(string $key): ?StockMove;

    /** @return iterable<StockMove> in ledger order */
    public function movesFor(int $productId, int $warehouseId): iterable;

    /** @return iterable<array{0:int,1:int}> [productId, warehouseId] pairs that have a balance row or moves */
    public function knownPairs(): iterable;
}
