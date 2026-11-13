<?php

declare(strict_types=1);

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Import\Application\LandedCostService;
use Modules\Import\Domain\CostBasis;
use Modules\Import\Domain\CostItem;
use Modules\Import\Domain\LandedCostResult;
use Modules\Import\Domain\Shipment;
use Modules\Import\Domain\ShipmentLine;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class ShipmentController extends ApiController
{
    public function store(Request $request, LandedCostService $service): JsonResponse
    {
        $d = $request->validate([
            'reference' => ['required', 'string', 'max:80', 'unique:shipments,reference'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', Decimal::RULE],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', Decimal::RULE],
            'lines.*.unit_price' => ['required', Decimal::RULE],
            'lines.*.volume_cbm' => ['nullable', Decimal::RULE],
            'lines.*.weight_kg' => ['nullable', Decimal::RULE],
            'lines.*.duty_rate' => ['required', Decimal::RULE],
            'costs' => ['array', 'max:50'],
            'costs.*.code' => ['required', 'string', 'max:40', 'not_in:PURCHASE,DUTY'],
            'costs.*.amount' => ['required', Decimal::RULE],
            'costs.*.basis' => ['required', 'in:value,volume,weight,quantity'],
            'costs.*.in_customs_value' => ['boolean'],
        ]);

        $shipment = $service->open(new Shipment(
            null, $d['reference'], strtoupper($d['currency']), Decimal::of($d['exchange_rate']), 'open',
            array_map(fn ($l) => new ShipmentLine(
                null, $l['product_id'], Decimal::of($l['quantity']), Decimal::of($l['unit_price']),
                isset($l['volume_cbm']) ? Decimal::of($l['volume_cbm']) : Fixed::zero(),
                isset($l['weight_kg']) ? Decimal::of($l['weight_kg']) : Fixed::zero(),
                Decimal::of($l['duty_rate']),
            ), $d['lines']),
            array_map(fn ($c) => new CostItem($c['code'], Decimal::of($c['amount']), CostBasis::from($c['basis']), (bool) ($c['in_customs_value'] ?? false)), $d['costs'] ?? []),
        ));

        return response()->json(['id' => $shipment->id, 'status' => $shipment->status], 201);
    }

    public function show(int $shipment, LandedCostService $service): JsonResponse
    {
        $s = $service->find($shipment) ?? abort(404);

        return response()->json([
            'id' => $s->id, 'reference' => $s->reference, 'currency' => $s->currency,
            'exchange_rate' => (string) $s->exchangeRate, 'status' => $s->status,
            'allocations' => $service->allocations($shipment),
        ]);
    }

    public function finalize(int $shipment, LandedCostService $service): JsonResponse
    {
        return response()->json(self::result($service->finalize($shipment)));
    }

    public function receive(Request $request, int $shipment, LandedCostService $service): JsonResponse
    {
        $d = $request->validate(['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);
        $this->warehouse($request, $d['warehouse_id']);
        $service->receive($shipment, $d['warehouse_id']);

        return response()->json(['id' => $shipment, 'status' => 'received']);
    }

    /** @return array<string,mixed> */
    private static function result(LandedCostResult $r): array
    {
        return [
            'total' => (string) $r->total,
            'lines' => array_map(fn ($l) => [
                'line_id' => $l->lineId, 'quantity' => (string) $l->quantity,
                'components' => array_map('strval', $l->components),
                'total' => (string) $l->total, 'unit_cost' => (string) $l->unitCost,
            ], $r->lines),
        ];
    }
}
