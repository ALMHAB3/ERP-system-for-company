<?php

declare(strict_types=1);

namespace Modules\Import\Domain\Strategies;

use Modules\Import\Domain\CostBasis;
use Modules\Shared\Domain\Fixed;

final class ByQuantity implements AllocationStrategy
{
    public function basis(): CostBasis
    {
        return CostBasis::Quantity;
    }

    public function weights(array $lines, Fixed $exchangeRate): array
    {
        $weights = [];
        foreach ($lines as $line) {
            $weights[$line->id] = $line->quantity->units;
        }

        return $weights;
    }
}
