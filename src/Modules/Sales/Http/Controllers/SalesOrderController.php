<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Permission;
use Modules\Sales\Application\OrderLineRequest;
use Modules\Sales\Application\SalesOrderService;
use Modules\Sales\Domain\SalesOrder;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class SalesOrderController extends ApiController
{
    public function store(Request $request, SalesOrderService $service): JsonResponse
    {
        $d = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'allow_below_margin' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', Decimal::RULE],
        ]);
        $this->warehouse($request, $d['warehouse_id']);
        $belowMargin = (bool) ($d['allow_below_margin'] ?? false);
        if ($belowMargin) {
            $this->access->authorize($this->userId($request), Permission::SalesApproveBelowMargin);
        }

        $order = $service->place($d['customer_id'], $d['warehouse_id'],
            array_map(fn ($l) => new OrderLineRequest($l['product_id'], Decimal::of($l['quantity'])), $d['lines']), $belowMargin);

        return response()->json($this->present($request, $order), 201);
    }

    public function show(Request $request, int $order, SalesOrderService $service): JsonResponse
    {
        $o = $this->authorizedOrder($request, $order, $service);

        return response()->json($this->present($request, $o));
    }

    public function ship(Request $request, int $order, SalesOrderService $service): JsonResponse
    {
        $this->authorizedOrder($request, $order, $service);

        return response()->json($this->present($request, $service->ship($order)));
    }

    public function invoice(Request $request, int $order, SalesOrderService $service): JsonResponse
    {
        $this->authorizedOrder($request, $order, $service);

        return response()->json(['invoice_id' => $service->invoice($order)], 201);
    }

    public function cancel(Request $request, int $order, SalesOrderService $service): JsonResponse
    {
        $this->authorizedOrder($request, $order, $service);
        $service->cancel($order);

        return response()->json(['id' => $order, 'status' => 'cancelled']);
    }

    private function authorizedOrder(Request $request, int $id, SalesOrderService $service): SalesOrder
    {
        $order = $service->find($id) ?? abort(404);
        $this->warehouse($request, $order->warehouseId);

        return $order;
    }

    /** @return array<string,mixed> */
    private function present(Request $request, SalesOrder $o): array
    {
        $showCost = $this->can($request, Permission::InventoryViewCost);

        return [
            'id' => $o->id, 'number' => $o->number, 'customer_id' => $o->customerId, 'warehouse_id' => $o->warehouseId,
            'status' => $o->status, 'net' => (string) $o->net(),
            'lines' => array_map(fn ($l) => array_filter([
                'id' => $l->id, 'product_id' => $l->productId, 'quantity' => (string) $l->quantity,
                'list_price' => (string) $l->listPrice, 'discount_rate' => (string) $l->discountRate,
                'unit_price' => (string) $l->unitPrice, 'total' => (string) $l->total(),
                'cost_of_goods' => $showCost && $l->costOfGoods ? (string) $l->costOfGoods : null,
            ], fn ($v) => $v !== null), $o->lines),
        ];
    }
}
