<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class LandedCostRequest
{
    /**
     * @param  list<CostableLine>  $lines
     * @param  list<CostItem>  $items
     */
    public function __construct(
        public array $lines,
        public array $items,
        public Fixed $exchangeRate,
    ) {}
}
