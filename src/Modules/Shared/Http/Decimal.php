<?php

declare(strict_types=1);

namespace Modules\Shared\Http;

use Modules\Shared\Domain\Fixed;

/** Validation + parsing of API decimals (strings or JSON numbers, max 4 decimals, never floats internally). */
final class Decimal
{
    public const RULE = 'regex:/^\d{1,13}(\.\d{1,4})?$/';

    public static function of(mixed $value): Fixed
    {
        return Fixed::parse((string) $value);
    }
}
