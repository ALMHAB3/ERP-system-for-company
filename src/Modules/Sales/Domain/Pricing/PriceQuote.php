<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Shared\Domain\Fixed;

final readonly class PriceQuote
{
    public function __construct(
        public Fixed $listPrice,
        public Fixed $discountRate,
        public Fixed $unitPrice,
        public string $source,
        /** True when the net price is under cost + the configured minimum margin: needs manager approval. */
        public bool $belowMinimumMargin,
    ) {}
}
