<?php

declare(strict_types=1);

namespace Modules\Import\Domain\Strategies;

use Modules\Import\Domain\CostableLine;
use Modules\Import\Domain\CostBasis;
use Modules\Shared\Domain\Fixed;

/** Strategy: how a shipment-level cost is shared among lines. */
interface AllocationStrategy
{
    public function basis(): CostBasis;

    /**
     * @param  list<CostableLine>  $lines
     * @return array<int|string,int> non-negative integer weight per line id
     */
    public function weights(array $lines, Fixed $exchangeRate): array;
}
