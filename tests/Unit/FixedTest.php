<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\Shared\Domain\Fixed;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class FixedTest extends TestCase
{
    public function test_parses_and_prints_exactly(): void
    {
        $this->assertSame('12.2550', (string) Fixed::parse('12.255'));
        $this->assertSame('-0.0500', (string) Fixed::parse('-0.05'));
        $this->assertSame('0.0000', (string) Fixed::parse('0'));
        $this->assertSame('1000.0000', (string) Fixed::of(1000));
    }

    public function test_rejects_more_than_four_decimals_and_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fixed::parse('1.23456');
    }

    public function test_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fixed::parse('1e5');
    }

    public function test_overflow_is_an_error_not_a_float(): void
    {
        $this->expectException(OverflowException::class);
        Fixed::parse('99999999999999999999');
    }

    public function test_multiplication_rounds_half_away_from_zero(): void
    {
        $this->assertSame('0.0003', (string) Fixed::parse('0.0005')->mul(Fixed::parse('0.5')));   // 0.00025 -> 0.0003
        $this->assertSame('-0.0003', (string) Fixed::parse('-0.0005')->mul(Fixed::parse('0.5')));
        $this->assertSame('555.0000', (string) Fixed::parse('11100')->mul(Fixed::parse('0.05')));
    }

    public function test_format_rounds_for_display_only(): void
    {
        $this->assertSame('12.26', Fixed::parse('12.255')->format(2));
        $this->assertSame('-12.26', Fixed::parse('-12.255')->format(2));
        $this->assertSame('13', Fixed::parse('12.5')->format(0));
    }

    public function test_allocation_always_sums_exactly(): void
    {
        $parts = Fixed::parse('100.00')->allocate(['a' => 1, 'b' => 1, 'c' => 1]);
        $sum = Fixed::zero();
        foreach ($parts as $p) {
            $sum = $sum->add($p);
        }
        $this->assertSame('100.0000', (string) $sum);
        $this->assertSame('33.3334', (string) $parts['a']); // first key takes the odd unit on a tie
        $this->assertSame('33.3333', (string) $parts['b']);
    }

    public function test_allocation_handles_negative_totals_and_zero_weights(): void
    {
        $parts = Fixed::parse('-10')->allocate(['a' => 3, 'b' => 0, 'c' => 7]);
        $this->assertSame('-3.0000', (string) $parts['a']);
        $this->assertSame('0.0000', (string) $parts['b']);
        $this->assertSame('-7.0000', (string) $parts['c']);
    }

    public function test_allocation_with_no_weight_fails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fixed::parse('10')->allocate(['a' => 0, 'b' => 0]);
    }
}
