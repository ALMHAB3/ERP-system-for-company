<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Application\PurchaseOrderService;
use Modules\Procurement\Domain\PurchaseOrderLine;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class PurchaseOrderController extends ApiController
{
    public function store(Request $request, PurchaseOrderService $service): JsonResponse
    {
        $d = $request->validate([
            'number' => ['required', 'string', 'max:40', 'unique:purchase_orders,number'],
            'supplier' => ['required', 'string', 'max:200'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', Decimal::RULE],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', Decimal::RULE],
            'lines.*.unit_price' => ['required', Decimal::RULE],
        ]);
        $order = $service->draft(
            $d['number'], $d['supplier'], strtoupper($d['currency']), Decimal::of($d['exchange_rate']),
            array_map(fn ($l) => new PurchaseOrderLine(null, $l['product_id'], Decimal::of($l['quantity']), Decimal::of($l['unit_price'])), $d['lines']),
            $this->userId($request),
        );

        return response()->json([
            'id' => $order->id, 'status' => $order->status->value, 'total' => (string) $order->total(),
            'required_approval_level' => $order->requiredApprovalLevel,
        ], 201);
    }

    public function submit(int $order, PurchaseOrderService $service): JsonResponse
    {
        $service->submit($order);

        return response()->json(['id' => $order, 'status' => 'pending_approval']);
    }

    public function approve(Request $request, int $order, PurchaseOrderService $service): JsonResponse
    {
        $userId = $this->userId($request);
        $service->approve($order, $userId, $this->access->approvalLevel($userId));

        return response()->json(['id' => $order, 'status' => 'approved']);
    }

    public function receive(Request $request, int $order, PurchaseOrderService $service): JsonResponse
    {
        $d = $request->validate(['warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);
        $this->warehouse($request, $d['warehouse_id']);
        $service->receiveLocal($order, $d['warehouse_id']);

        return response()->json(['id' => $order, 'status' => 'received']);
    }
}
