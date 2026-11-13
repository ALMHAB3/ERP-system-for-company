<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Modules\Inventory\Application\AtpService;
use Modules\Sales\Domain\Ports\IssuedLine;
use Modules\Sales\Domain\Ports\StockAvailability;
use Modules\Shared\Domain\Fixed;

/** Adapter: Sales -> Inventory ATP. */
final class AtpStockAvailability implements StockAvailability
{
    public function __construct(private readonly AtpService $atp) {}

    public function reserve(int $productId, int $warehouseId, Fixed $quantity, string $reference): int
    {
        return (int) $this->atp->reserve($productId, $warehouseId, $quantity, $reference)->id;
    }

    public function release(int $reservationId): void
    {
        $this->atp->release($reservationId);
    }

    public function issue(int $reservationId, string $reference, string $idempotencyKey): array
    {
        return array_map(
            static fn ($m) => new IssuedLine((int) $m->id, (string) $m->lotNo, $m->quantity, $m->totalCost),
            $this->atp->consume($reservationId, $reference, $idempotencyKey)->moves,
        );
    }
}
