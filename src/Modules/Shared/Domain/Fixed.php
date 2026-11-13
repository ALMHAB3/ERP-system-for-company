<?php

declare(strict_types=1);

namespace Modules\Shared\Domain;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;
use Stringable;

/**
 * Exact fixed-point number with 4 decimal places, stored as an integer count of 1/10000.
 * Used for money, quantities, rates and measures: no floats ever touch accounting maths.
 * Overflow is detected and raised, never silently converted to float.
 */
final class Fixed implements JsonSerializable, Stringable
{
    public const SCALE = 4;

    public const FACTOR = 10000;

    private function __construct(public readonly int $units) {}

    public static function units(int $units): self
    {
        return new self($units);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function of(int|string|self $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_int($value) => self::fromWhole($value),
            default => self::parse($value),
        };
    }

    public static function fromWhole(int $whole): self
    {
        $units = $whole * self::FACTOR;
        if (! is_int($units)) {
            throw new OverflowException("Value {$whole} is too large.");
        }

        return new self($units);
    }

    public static function parse(string $text): self
    {
        $text = trim($text);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,4}))?$/', $text, $m)) {
            throw new InvalidArgumentException("Invalid decimal '{$text}' (max 4 decimal places).");
        }
        $whole = (int) $m[2];
        if ((string) $whole !== ltrim($m[2], '0') && ! ($whole === 0 && ltrim($m[2], '0') === '')) {
            throw new OverflowException("Value {$text} is too large.");
        }
        $units = self::fromWhole($whole)->units + (isset($m[3]) ? (int) str_pad($m[3], 4, '0') : 0);

        return new self($m[1] === '-' ? -$units : $units);
    }

    public function add(self $other): self
    {
        $sum = $this->units + $other->units;
        if (! is_int($sum)) {
            throw new OverflowException('Addition overflow.');
        }

        return new self($sum);
    }

    public function sub(self $other): self
    {
        $diff = $this->units - $other->units;
        if (! is_int($diff)) {
            throw new OverflowException('Subtraction overflow.');
        }

        return new self($diff);
    }

    public function neg(): self
    {
        return new self(-$this->units);
    }

    public function abs(): self
    {
        return new self(abs($this->units));
    }

    /** Multiplication rounded half away from zero to 4 decimals. */
    public function mul(self $other): self
    {
        return new self(self::mulDiv($this->units, $other->units, self::FACTOR));
    }

    /** Division rounded half away from zero to 4 decimals. */
    public function div(self $other): self
    {
        return new self(self::mulDiv($this->units, self::FACTOR, $other->units));
    }

    /** round($a * $b / $c) with exact integer arithmetic (half away from zero). */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        $product = $a * $b;
        if (! is_int($product)) {
            throw new OverflowException('Multiplication overflow.');
        }

        return self::divRound($product, $c);
    }

    public static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new \DivisionByZeroError('Division by zero.');
        }
        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return $quotient;
    }

    /**
     * Largest-remainder allocation: splits this amount across the given non-negative
     * integer weights so that the parts ALWAYS sum exactly to the amount.
     *
     * @param  array<int|string,int>  $weights
     * @return array<int|string,self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Cannot allocate across zero weights.');
        }
        $total = 0;
        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Allocation weights must be non-negative.');
            }
            $total += $weight;
        }
        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation weights sum to zero.');
        }

        $sign = $this->units < 0 ? -1 : 1;
        $amount = abs($this->units);
        $base = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $key => $weight) {
            $product = $amount * $weight;
            if (! is_int($product)) {
                throw new OverflowException('Allocation overflow.');
            }
            $base[$key] = intdiv($product, $total);
            $remainders[$key] = $product % $total;
            $assigned += $base[$key];
        }

        $keys = array_keys($remainders);
        usort($keys, static fn ($a, $b) => $remainders[$b] <=> $remainders[$a]); // stable sort
        for ($i = 0, $left = $amount - $assigned; $i < $left; $i++) {
            $base[$keys[$i]]++;
        }

        return array_map(static fn (int $u) => new self($sign * $u), $base);
    }

    public function compare(self $other): int
    {
        return $this->units <=> $other->units;
    }

    public function equals(self $other): bool
    {
        return $this->units === $other->units;
    }

    public function gt(self $other): bool
    {
        return $this->units > $other->units;
    }

    public function gte(self $other): bool
    {
        return $this->units >= $other->units;
    }

    public function lt(self $other): bool
    {
        return $this->units < $other->units;
    }

    public function lte(self $other): bool
    {
        return $this->units <= $other->units;
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isPositive(): bool
    {
        return $this->units > 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    public function __toString(): string
    {
        $abs = abs($this->units);

        return ($this->units < 0 ? '-' : '')
            .intdiv($abs, self::FACTOR).'.'
            .str_pad((string) ($abs % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);
    }

    /** Display string rounded half away from zero to $decimals (0-4). */
    public function format(int $decimals = 2): string
    {
        $step = 10 ** (self::SCALE - $decimals);
        $rounded = self::divRound($this->units, $step) * $step;
        $abs = abs($rounded);
        $int = intdiv($abs, self::FACTOR);
        $frac = $decimals > 0
            ? '.'.substr(str_pad((string) ($abs % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT), 0, $decimals)
            : '';

        return ($rounded < 0 ? '-' : '').$int.$frac;
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
