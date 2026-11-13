<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Modules\Shared\Domain\Fixed;

final readonly class OrderLineRequest
{
    public function __construct(public int $productId, public Fixed $quantity) {}
}
