<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Sales\Domain\Pricing\PriceRounder;
use Modules\Shared\Domain\Fixed;
use PHPUnit\Framework\TestCase;

final class PriceRounderTest extends TestCase
{
    public function test_rounds_up_to_the_next_multiple_of_five(): void
    {
        $r = new PriceRounder(Fixed::of(5));
        foreach ([['356', '360'], ['353', '355'], ['355', '355'], ['355.0001', '360'], ['351', '355'], ['360', '360'], ['0.01', '5'], ['4.9999', '5'], ['1234.5', '1235']] as [$in, $out]) {
            $this->assertSame($out.'.0000', (string) $r->up(Fixed::parse($in)), "{$in} -> {$out}");
        }
    }

    public function test_other_steps_and_non_positive_amounts(): void
    {
        $this->assertSame('0.5000', (string) (new PriceRounder(Fixed::parse('0.25')))->up(Fixed::parse('0.26')));
        $this->assertSame('360.0000', (string) (new PriceRounder(Fixed::of(10)))->up(Fixed::of(356)));
        $this->assertSame('0.0000', (string) (new PriceRounder(Fixed::of(5)))->up(Fixed::zero()));
        $this->expectException(\InvalidArgumentException::class);
        new PriceRounder(Fixed::zero());
    }
}
