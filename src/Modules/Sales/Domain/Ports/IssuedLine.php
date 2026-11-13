<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Ports;

use Modules\Shared\Domain\Fixed;

final readonly class IssuedLine
{
    public function __construct(public int $stockMoveId, public string $lotNo, public Fixed $quantity, public Fixed $cost) {}
}
