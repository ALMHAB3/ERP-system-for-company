<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class LandedCostLineResult
{
    /** @param array<string,Fixed> $components purchase + each cost code + DUTY */
    public function __construct(
        public int|string $lineId,
        public Fixed $quantity,
        public array $components,
        public Fixed $total,
        public Fixed $unitCost,
    ) {}
}
