<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Permission;
use Modules\Inventory\Application\AtpService;
use Modules\Inventory\Application\InventoryLedger;
use Modules\Inventory\Domain\MoveType;
use Modules\Inventory\Domain\StockMove;
use Modules\Inventory\Domain\StockMoveData;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class InventoryController extends ApiController
{
    public function balances(Request $request): JsonResponse
    {
        $f = $request->validate(['product_id' => ['integer'], 'warehouse_id' => ['integer']]);
        $userId = $this->userId($request);
        if (isset($f['warehouse_id'])) {
            $this->warehouse($request, $f['warehouse_id']);
        }
        $showCost = $this->can($request, Permission::InventoryViewCost);

        $rows = DB::table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->when(isset($f['product_id']), fn ($q) => $q->where('b.product_id', $f['product_id']))
            ->when(isset($f['warehouse_id']), fn ($q) => $q->where('b.warehouse_id', $f['warehouse_id']))
            ->orderBy('p.sku')->orderBy('b.warehouse_id')->limit(1000)
            ->get(['b.product_id', 'p.sku', 'b.warehouse_id', 'b.on_hand', 'b.reserved', 'b.value'])
            ->filter(fn ($r) => $this->access->canUseWarehouse($userId, (int) $r->warehouse_id))
            ->map(function ($r) use ($showCost) {
                $onHand = Fixed::units((int) $r->on_hand);
                $reserved = Fixed::units((int) $r->reserved);
                $row = [
                    'product_id' => (int) $r->product_id, 'sku' => $r->sku, 'warehouse_id' => (int) $r->warehouse_id,
                    'on_hand' => (string) $onHand, 'reserved' => (string) $reserved, 'available' => (string) $onHand->sub($reserved),
                ];
                if ($showCost) { // cost and margin data are a separate, sensitive permission
                    $value = Fixed::units((int) $r->value);
                    $row['value'] = (string) $value;
                    $row['average_cost'] = $onHand->isPositive() ? (string) $value->div($onHand) : null;
                }

                return $row;
            })->values();

        return response()->json(['data' => $rows]);
    }

    public function adjust(Request $request, InventoryLedger $ledger): JsonResponse
    {
        $d = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['required', Decimal::RULE],
            'unit_cost' => ['required_if:direction,in', Decimal::RULE],
            'lot_no' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:100'],
        ]);
        $this->warehouse($request, $d['warehouse_id']);

        $moves = $ledger->recordMany(new StockMoveData(
            $d['product_id'], $d['warehouse_id'],
            $d['direction'] === 'in' ? MoveType::AdjustmentIn : MoveType::AdjustmentOut,
            Decimal::of($d['quantity']),
            isset($d['unit_cost']) ? Decimal::of($d['unit_cost']) : null,
            'adjustment:'.$d['reason'],
            lotNo: $d['lot_no'] ?? null,
            idempotencyKey: $this->idempotencyKey($request, 'adjustment'),
            expiryDate: isset($d['expiry_date']) ? new \DateTimeImmutable($d['expiry_date']) : null,
        ));

        return response()->json(['id' => $moves[0]->id, 'moves' => array_map(self::move(...), $moves)], 201);
    }

    public function transfer(Request $request, InventoryLedger $ledger): JsonResponse
    {
        $d = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'quantity' => ['required', Decimal::RULE],
            'reference' => ['required', 'string', 'max:80'],
            'lot_no' => ['nullable', 'string', 'max:64'],
        ]);
        $this->warehouse($request, $d['from_warehouse_id'], $d['to_warehouse_id']);

        [$out, $in] = $ledger->transfer($d['product_id'], $d['from_warehouse_id'], $d['to_warehouse_id'], Decimal::of($d['quantity']), 'transfer:'.$d['reference'], $d['lot_no'] ?? null);

        return response()->json(['out' => array_map(self::move(...), $out), 'in' => array_map(self::move(...), $in)], 201);
    }

    public function atp(Request $request, AtpService $atp): JsonResponse
    {
        $d = $request->validate(['product_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer']]);
        $this->warehouse($request, $d['warehouse_id']);

        return response()->json(['available_to_promise' => (string) $atp->availableToPromise($d['product_id'], $d['warehouse_id'])]);
    }

    /** @return array<string,mixed> */
    private static function move(StockMove $m): array
    {
        return [
            'id' => $m->id, 'type' => $m->type->value, 'product_id' => $m->productId, 'warehouse_id' => $m->warehouseId,
            'lot_no' => $m->lotNo, 'quantity' => (string) $m->quantity, 'balance_qty_after' => (string) $m->balanceQtyAfter, 'reference' => $m->reference,
        ];
    }
}
