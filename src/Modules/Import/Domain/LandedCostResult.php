<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

final readonly class LandedCostResult
{
    /** @param list<LandedCostLineResult> $lines */
    public function __construct(public array $lines, public Fixed $total) {}

    public function line(int|string $id): LandedCostLineResult
    {
        foreach ($this->lines as $line) {
            if ($line->lineId === $id) {
                return $line;
            }
        }
        throw new \OutOfBoundsException("No landed cost for line {$id}.");
    }
}
