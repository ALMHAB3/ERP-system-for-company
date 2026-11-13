<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use Modules\Shared\Domain\Fixed;

/** Customs duty = customs value (CIF) x the duty rate of the line's HS code. */
final class DutyCalculator
{
    public function duty(Fixed $customsValue, Fixed $rate): Fixed
    {
        return $customsValue->mul($rate);
    }
}
