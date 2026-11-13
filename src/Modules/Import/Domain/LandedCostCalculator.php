<?php

declare(strict_types=1);

namespace Modules\Import\Domain;

use InvalidArgumentException;
use LogicException;
use Modules\Import\Domain\Exceptions\MissingAllocationBasis;
use Modules\Shared\Domain\Fixed;

/**
 * Landed cost = purchase value + allocated freight/insurance/handling + customs duty.
 *
 * 1. Costs that belong to the customs value (freight, insurance) are allocated first.
 * 2. Duty is computed per line on its customs value (purchase + those costs) x its own duty rate.
 * 3. Remaining costs (port, clearance, local transport...) are allocated on their own basis.
 *
 * Every allocation uses largest-remainder rounding, so the sum of the lines equals the
 * shipment total to the last 1/10000 - checked by an invariant before returning.
 */
final class LandedCostCalculator
{
    public const PURCHASE = 'PURCHASE';

    public const DUTY = 'DUTY';

    public function __construct(
        private readonly AllocationStrategyFactory $strategies,
        private readonly DutyCalculator $duties,
    ) {}

    public function calculate(LandedCostRequest $request): LandedCostResult
    {
        if ($request->lines === []) {
            throw new InvalidArgumentException('A shipment needs at least one line.');
        }
        if (! $request->exchangeRate->isPositive()) {
            throw new InvalidArgumentException('Exchange rate must be positive.');
        }

        $components = [];
        $customsValue = [];
        $rate = $request->exchangeRate;
        foreach ($request->lines as $line) {
            if (isset($components[$line->id])) {
                throw new InvalidArgumentException("Duplicate line id {$line->id}.");
            }
            if (! $line->quantity->isPositive()) {
                throw new InvalidArgumentException("Line {$line->id} must have a positive quantity.");
            }
            $purchase = $line->purchaseValue($rate);
            $components[$line->id] = [self::PURCHASE => $purchase];
            $customsValue[$line->id] = $purchase;
        }

        foreach ($request->items as $item) {
            if (! $item->inCustomsValue) {
                continue;
            }
            foreach ($this->allocate($item, $request) as $lineId => $share) {
                $components[$lineId][$item->code] = ($components[$lineId][$item->code] ?? Fixed::zero())->add($share);
                $customsValue[$lineId] = $customsValue[$lineId]->add($share);
            }
        }

        foreach ($request->lines as $line) {
            $components[$line->id][self::DUTY] = $this->duties->duty($customsValue[$line->id], $line->dutyRate);
        }

        foreach ($request->items as $item) {
            if ($item->inCustomsValue) {
                continue;
            }
            foreach ($this->allocate($item, $request) as $lineId => $share) {
                $components[$lineId][$item->code] = ($components[$lineId][$item->code] ?? Fixed::zero())->add($share);
            }
        }

        $results = [];
        $grand = Fixed::zero();
        foreach ($request->lines as $line) {
            $total = Fixed::zero();
            foreach ($components[$line->id] as $amount) {
                $total = $total->add($amount);
            }
            $results[] = new LandedCostLineResult($line->id, $line->quantity, $components[$line->id], $total, $total->div($line->quantity));
            $grand = $grand->add($total);
        }

        $this->assertInvariant($request, $results, $grand);

        return new LandedCostResult($results, $grand);
    }

    /** @return array<int|string,Fixed> */
    private function allocate(CostItem $item, LandedCostRequest $request): array
    {
        $weights = $this->strategies->for($item->basis)->weights($request->lines, $request->exchangeRate);
        if (array_sum($weights) <= 0) {
            throw new MissingAllocationBasis("Cannot allocate '{$item->code}' by {$item->basis->value}: no line has that measure. Enter volume/weight or choose another basis.");
        }

        return $item->amount->allocate($weights);
    }

    /** @param list<LandedCostLineResult> $results */
    private function assertInvariant(LandedCostRequest $request, array $results, Fixed $grand): void
    {
        $expected = Fixed::zero();
        foreach ($request->lines as $line) {
            $expected = $expected->add($line->purchaseValue($request->exchangeRate));
        }
        foreach ($request->items as $item) {
            $expected = $expected->add($item->amount);
        }
        foreach ($results as $result) {
            $expected = $expected->add($result->components[self::DUTY]);
        }
        if (! $expected->equals($grand)) {
            throw new LogicException("Landed cost invariant violated: lines sum to {$grand}, expected {$expected}.");
        }
    }
}
