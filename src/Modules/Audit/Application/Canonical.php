<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use JsonSerializable;
use Stringable;

/** Deterministic JSON (sorted keys, no float drift) so a hash can be recomputed byte-for-byte later. */
final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(self::normalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $value->format(DATE_ATOM);
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_float($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = array_map(self::normalise(...), $value);
        if (! array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }
}
