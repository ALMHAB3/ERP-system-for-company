<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Application\ExchangeRates;
use Modules\Identity\Domain\Permission;
use Modules\Import\Domain\CostableLine;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\LandedCostCalculator;
use Modules\Import\Domain\LandedCostRequest;
use Modules\Sales\Application\PriceSuggestionService;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

/** Live estimate while a shipment is being typed: nothing is saved, nothing is posted. */
final class ShipmentPreviewController extends ApiController
{
    public function __invoke(Request $request, LandedCostCalculator $calculator, ExchangeRates $rates, PriceSuggestionService $pricing): JsonResponse
    {
        $this->access->authorize($this->userId($request), Permission::InventoryViewCost);   // the estimate reveals cost
        $d = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', Decimal::RULE],
            'margin_pct' => ['nullable', Decimal::RULE],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.quantity' => ['required', Decimal::RULE],
            'lines.*.unit_price' => ['required', Decimal::RULE],
            'lines.*.volume_cbm' => ['nullable', Decimal::RULE],
            'lines.*.weight_kg' => ['nullable', Decimal::RULE],
            'lines.*.duty_rate' => ['nullable', Decimal::RULE],
            'costs' => ['array', 'max:50'],
            'costs.*.code' => ['required', 'string', 'max:40', 'not_in:PURCHASE,DUTY'],
            'costs.*.amount' => ['required', Decimal::RULE],
            'costs.*.basis' => ['required', 'in:value,volume,weight,quantity'],
            'costs.*.in_customs_value' => ['boolean'],
        ]);

        $rate = $rates->resolve($d['currency'], $d['exchange_rate'] ?? null);
        $lines = [];
        foreach ($d['lines'] as $i => $l) {
            $lines[] = new CostableLine($i, Decimal::of($l['quantity']), Decimal::of($l['unit_price']),
                isset($l['volume_cbm']) ? Decimal::of($l['volume_cbm']) : Fixed::zero(), isset($l['weight_kg']) ? Decimal::of($l['weight_kg']) : Fixed::zero(),
                isset($l['duty_rate']) ? Decimal::of($l['duty_rate']) : Fixed::zero());
        }
        $items = array_map(fn ($c) => new CostItem($c['code'], Decimal::of($c['amount']), CostBasis::from($c['basis']), (bool) ($c['in_customs_value'] ?? false)), $d['costs'] ?? []);
        $result = $calculator->calculate(new LandedCostRequest($lines, $items, $rate));

        $rounder = $pricing->rounder();
        $margins = [];
        foreach (['wholesale', 'retail', 'direct'] as $category) {
            $margins[$category] = isset($d['margin_pct']) ? Decimal::of($d['margin_pct']) : Fixed::parse((string) config("erp.pricing.margins.{$category}"));
        }

        return response()->json([
            'base' => $rates->base(), 'rate' => (string) $rate, 'step' => (string) $rounder->step(), 'margins' => array_map('strval', $margins), 'total' => (string) $result->total,
            'lines' => array_map(function ($lr, $line) use ($rounder, $margins, $rate) {
                $unit = $lr->unitCost;

                return [
                    'purchase_unit_base' => (string) $line->unitPrice->mul($rate),          // price x rate, before freight/duty
                    'landed_unit_cost' => (string) $unit, 'landed_total' => (string) $lr->total,
                    'components' => array_map('strval', $lr->components),
                    'suggested' => array_map(fn (Fixed $m) => (string) $rounder->up($unit->add($unit->mul($m->div(Fixed::of(100))))), $margins),
                ];
            }, $result->lines, $lines),
        ]);
    }
}
