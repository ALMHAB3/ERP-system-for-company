<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Ports;

/** Serial-numbered products: shipping needs the units named; cancelling frees them. */
interface SerialTracking
{
    public function sellForOrder(int $orderId): void;

    public function releaseForOrder(int $orderId): void;
}
