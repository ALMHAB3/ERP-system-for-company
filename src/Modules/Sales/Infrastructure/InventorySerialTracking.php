<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Modules\Inventory\Application\SerialService;
use Modules\Sales\Domain\Ports\SerialTracking;

/** Adapter: Sales -> Inventory serial numbers. */
final class InventorySerialTracking implements SerialTracking
{
    public function __construct(private readonly SerialService $serials) {}

    public function sellForOrder(int $orderId): void
    {
        $this->serials->sellForOrder($orderId);
    }

    public function releaseForOrder(int $orderId): void
    {
        $this->serials->releaseForOrder($orderId);
    }
}
