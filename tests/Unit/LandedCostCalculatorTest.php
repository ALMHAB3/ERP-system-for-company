<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\Import\Domain\AllocationStrategyFactory;
use Modules\Import\Domain\CostableLine;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\DutyCalculator;
use Modules\Import\Domain\Exceptions\MissingAllocationBasis;
use Modules\Import\Domain\LandedCostCalculator;
use Modules\Import\Domain\LandedCostRequest;
use Modules\Import\Domain\Strategies\ByQuantity;
use Modules\Import\Domain\Strategies\ByValue;
use Modules\Import\Domain\Strategies\ByVolume;
use Modules\Import\Domain\Strategies\ByWeight;
use Modules\Shared\Domain\Fixed;
use PHPUnit\Framework\TestCase;

final class LandedCostCalculatorTest extends TestCase
{
    private function calculator(): LandedCostCalculator
    {
        return new LandedCostCalculator(
            new AllocationStrategyFactory([new ByValue, new ByVolume, new ByWeight, new ByQuantity]),
            new DutyCalculator,
        );
    }

    private function line(string|int $id, string $qty, string $price, string $cbm, string $kg, string $duty): CostableLine
    {
        return new CostableLine($id, Fixed::parse($qty), Fixed::parse($price), Fixed::parse($cbm), Fixed::parse($kg), Fixed::parse($duty));
    }

    /** The worked example in the design document (section 3.2). */
    public function test_reproduces_the_design_document_example(): void
    {
        $result = $this->calculator()->calculate(new LandedCostRequest(
            [
                $this->line('A', '1000', '10', '10', '2000', '0.05'),
                $this->line('B', '500', '40', '20', '1000', '0.15'),
            ],
            [
                new CostItem('FREIGHT', Fixed::parse('3000'), CostBasis::Volume, inCustomsValue: true),
                new CostItem('INSURANCE', Fixed::parse('300'), CostBasis::Value, inCustomsValue: true),
                new CostItem('CLEARANCE', Fixed::parse('900'), CostBasis::Weight),
            ],
            Fixed::of(1),
        ));

        $a = $result->line('A');
        $b = $result->line('B');
        $this->assertSame('1000.0000', (string) $a->components['FREIGHT']);
        $this->assertSame('100.0000', (string) $a->components['INSURANCE']);
        $this->assertSame('555.0000', (string) $a->components[LandedCostCalculator::DUTY]);
        $this->assertSame('600.0000', (string) $a->components['CLEARANCE']);
        $this->assertSame('12255.0000', (string) $a->total);
        $this->assertSame('12.2550', (string) $a->unitCost);

        $this->assertSame('3330.0000', (string) $b->components[LandedCostCalculator::DUTY]);
        $this->assertSame('25830.0000', (string) $b->total);
        $this->assertSame('51.6600', (string) $b->unitCost);

        $this->assertSame('38085.0000', (string) $result->total);
    }

    public function test_exchange_rate_converts_the_invoice_currency(): void
    {
        $result = $this->calculator()->calculate(new LandedCostRequest(
            [$this->line(1, '10', '100', '1', '1', '0')],
            [],
            Fixed::parse('3.75'), // 10 x 100 USD x 3.75
        ));
        $this->assertSame('3750.0000', (string) $result->total);
    }

    public function test_odd_amounts_never_lose_a_unit(): void
    {
        $lines = [];
        for ($i = 1; $i <= 7; $i++) {
            $lines[] = $this->line($i, (string) ($i * 3), '1.1111', (string) $i, (string) ($i * 7), '0.0725');
        }
        $result = $this->calculator()->calculate(new LandedCostRequest(
            $lines,
            [
                new CostItem('FREIGHT', Fixed::parse('1234.5679'), CostBasis::Volume, true),
                new CostItem('PORT', Fixed::parse('0.01'), CostBasis::Quantity),
            ],
            Fixed::parse('3.7512'),
        ));

        $sum = Fixed::zero();
        foreach ($result->lines as $l) {
            $sum = $sum->add($l->total);
        }
        $this->assertTrue($sum->equals($result->total));
    }

    public function test_cannot_allocate_by_volume_when_no_line_has_volume(): void
    {
        $this->expectException(MissingAllocationBasis::class);
        $this->calculator()->calculate(new LandedCostRequest(
            [$this->line(1, '1', '10', '0', '0', '0')],
            [new CostItem('FREIGHT', Fixed::parse('50'), CostBasis::Volume)],
            Fixed::of(1),
        ));
    }

    public function test_rejects_duplicate_lines_and_empty_shipments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator()->calculate(new LandedCostRequest(
            [$this->line(1, '1', '1', '1', '1', '0'), $this->line(1, '1', '1', '1', '1', '0')], [], Fixed::of(1),
        ));
    }
}
