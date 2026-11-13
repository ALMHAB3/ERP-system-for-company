<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use Modules\Shared\Domain\Fixed;

final readonly class PriceCandidate
{
    public function __construct(public Fixed $price, public string $source) {}
}
