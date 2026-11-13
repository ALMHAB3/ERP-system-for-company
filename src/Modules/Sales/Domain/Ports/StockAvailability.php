<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Ports;

use Modules\Shared\Domain\Fixed;

/** Port towards inventory ATP (adapter keeps Sales away from Inventory internals). */
interface StockAvailability
{
    /** @return int reservation id; throws when stock is not available */
    public function reserve(int $productId, int $warehouseId, Fixed $quantity, string $reference): int;

    public function release(int $reservationId): void;

    /** Issues the reserved stock; returns the cost of goods issued (moving average x quantity). */
    public function issue(int $reservationId, string $reference, string $idempotencyKey): Fixed;
}
