<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Inventory\Application\SerialService;
use Modules\Shared\Http\ApiController;

final class SerialController extends ApiController
{
    public function register(Request $request, SerialService $service, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'serials' => ['required', 'array', 'min:1', 'max:500'], 'serials.*' => ['string', 'max:80']]);
        $this->warehouse($request, $d['warehouse_id']);
        $n = $service->register($d['product_id'], $d['warehouse_id'], $d['serials']);
        $audit->record('serials.registered', 'product', $d['product_id'], ['warehouse_id' => $d['warehouse_id'], 'count' => $n]);

        return response()->json(['registered' => $n], 201);
    }

    public function assign(Request $request, int $order, int $line, SerialService $service, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['serials' => ['present', 'array', 'max:500'], 'serials.*' => ['string', 'max:80']]);
        $warehouse = (int) DB::table('sales_orders')->where('id', $order)->value('warehouse_id');
        abort_if($warehouse === 0, 404);
        $this->warehouse($request, $warehouse);
        $service->assign($order, $line, $d['serials'] === [] ? [] : $d['serials']);
        $audit->record('serials.assigned', 'sales_order', $order, ['line' => $line, 'count' => count($d['serials'])]);

        return response()->json(null, 204);
    }

    public function returned(Request $request, SerialService $service, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'serials' => ['required', 'array', 'min:1', 'max:500'], 'serials.*' => ['string', 'max:80']]);
        $this->warehouse($request, $d['warehouse_id']);
        $n = $service->returnToStock($d['product_id'], $d['serials'], $d['warehouse_id']);
        $audit->record('serials.returned', 'product', $d['product_id'], ['count' => $n]);

        return response()->json(['returned' => $n]);
    }

    public function trace(string $serial, SerialService $service): JsonResponse
    {
        $rows = $service->trace($serial);
        abort_if($rows === [], 404);

        return response()->json(['data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['product_id' => ['integer'], 'status' => ['in:in_stock,reserved,sold']]);
        $rows = DB::table('product_serials as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->when(isset($f['product_id']), fn ($q) => $q->where('s.product_id', $f['product_id']))->when(isset($f['status']), fn ($q) => $q->where('s.status', $f['status']))
            ->orderByDesc('s.id')->limit(500)->get(['s.id', 's.serial', 's.status', 'p.sku', 'p.name as product', 'w.name as warehouse', 's.sold_at']);

        return response()->json(['data' => $rows]);
    }
}
