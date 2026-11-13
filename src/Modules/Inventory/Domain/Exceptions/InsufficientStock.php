<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exceptions;

use DomainException;
use Modules\Shared\Domain\Fixed;

final class InsufficientStock extends DomainException
{
    public static function for(int $productId, int $warehouseId, Fixed $requested, Fixed $available): self
    {
        return new self("Insufficient stock for product {$productId} in warehouse {$warehouseId}: requested {$requested}, available {$available}.");
    }
}
