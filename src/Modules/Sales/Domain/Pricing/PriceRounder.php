<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Pricing;

use InvalidArgumentException;
use Modules\Shared\Domain\Fixed;

/** Rounds a selling price UP to the next multiple of a step (step 5: 353 -> 355, 356 -> 360, 355 -> 355). Exact integer maths. */
final class PriceRounder
{
    public function __construct(private readonly Fixed $step)
    {
        if (! $step->isPositive()) {
            throw new InvalidArgumentException('Rounding step must be positive.');
        }
    }

    public function up(Fixed $amount): Fixed
    {
        if (! $amount->isPositive()) {
            return Fixed::zero();
        }
        $step = $this->step->units;
        $multiples = intdiv($amount->units + $step - 1, $step);

        return Fixed::units($multiples * $step);
    }

    public function step(): Fixed
    {
        return $this->step;
    }
}
