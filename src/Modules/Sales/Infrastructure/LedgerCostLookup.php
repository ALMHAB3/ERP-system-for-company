<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Modules\Inventory\Domain\StockLedgerRepository;
use Modules\Sales\Domain\Ports\CostLookup;
use Modules\Shared\Domain\Fixed;

final class LedgerCostLookup implements CostLookup
{
    public function __construct(private readonly StockLedgerRepository $ledger) {}

    public function averageCost(int $productId, int $warehouseId): ?Fixed
    {
        return $this->ledger->balance($productId, $warehouseId)->averageCost();
    }
}
