<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Finance\Application\ExchangeRates;
use Modules\Identity\Domain\AccessRepository;
use Modules\Identity\Domain\Permission;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

/** Read models for the screens (lists, lookups) plus two small master-data updates. */
final class ListController extends ApiController
{
    /** Small reference data the forms need, already limited to what this user may touch. */
    public function lookups(Request $request, ExchangeRates $rates): JsonResponse
    {
        $userId = $this->userId($request);

        return response()->json([
            'company_name' => config('erp.company_name'),
            'demo' => DB::table('system_flags')->where('name', 'demo_data')->exists(),
            'currency' => config('erp.base_currency'),
            'currencies' => collect($rates->latest())->map(fn ($r, $code) => ['code' => $code, 'name' => config('erp.currencies')[$code] ?? $code, 'rate' => $r['rate'], 'rate_date' => $r['date']])->values(),
            'vat_rate' => (string) config('erp.vat_rate'),
            'products' => DB::table('products')->where('active', true)->orderBy('sku')->limit(5000)->get(['id', 'sku', 'name', 'base_uom', 'lead_time_days', 'cover_days', 'tracks_serials', 'has_expiry']),
            'warehouses' => DB::table('warehouses')->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'type'])
                ->filter(fn ($w) => $this->access->canUseWarehouse($userId, (int) $w->id))->values(),
            'customers' => DB::table('customers')->orderBy('is_walk_in', 'desc')->orderBy('name')->limit(5000)->get(['id', 'name', 'category', 'credit_limit', 'terms_days', 'is_walk_in', 'tax_id', 'address'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'category' => $c->category, 'credit_limit' => (string) Fixed::units((int) $c->credit_limit), 'terms_days' => (int) $c->terms_days, 'is_walk_in' => (bool) $c->is_walk_in, 'tax_id' => $c->tax_id, 'address' => $c->address]),
            'bank_accounts' => DB::table('bank_accounts')->where('active', true)->orderBy('id')->get(['id', 'name', 'account_number', 'currency', 'gl_account']),
            'suppliers' => DB::table('suppliers')->where('active', true)->orderBy('name')->get(['id', 'name', 'currency', 'terms_days']),
            'accounts' => DB::table('accounts')->orderBy('code')->get(['code', 'name', 'type']),
            'roles' => DB::table('roles')->orderBy('name')->get(['name', 'label', 'approval_level']),
        ]);
    }

    public function shipments(): JsonResponse
    {
        $rows = DB::table('shipments as s')->orderByDesc('s.id')->limit(300)->get()->map(function ($s) {
            $lines = DB::table('shipment_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.shipment_id', $s->id)->orderBy('l.id')
                ->get(['l.id', 'p.sku', 'p.name', 'l.quantity', 'l.unit_price', 'l.lot_no', 'l.expiry_date', 'l.duty_rate', 'l.sale_price']);
            $costs = DB::table('shipment_cost_items')->where('shipment_id', $s->id)->orderBy('id')->get(['code', 'amount', 'basis', 'in_customs_value']);
            $alloc = DB::table('landed_cost_allocations')->where('shipment_id', $s->id)->selectRaw('shipment_line_id, sum(amount) as total')->groupBy('shipment_line_id')->pluck('total', 'shipment_line_id');

            return [
                'id' => (int) $s->id, 'reference' => $s->reference, 'currency' => $s->currency, 'exchange_rate' => (string) Fixed::units((int) $s->exchange_rate), 'status' => $s->status,
                'lines' => $lines->map(fn ($l) => [
                    'sku' => $l->sku, 'name' => $l->name, 'quantity' => (string) Fixed::units((int) $l->quantity), 'unit_price' => (string) Fixed::units((int) $l->unit_price),
                    'lot_no' => $l->lot_no, 'expiry_date' => $l->expiry_date, 'sale_price' => $l->sale_price !== null ? (string) Fixed::units((int) $l->sale_price) : null, 'duty_rate' => (string) Fixed::units((int) $l->duty_rate),
                    'landed_total' => isset($alloc[$l->id]) ? (string) Fixed::units((int) $alloc[$l->id]) : null,
                    'landed_unit' => isset($alloc[$l->id]) ? (string) Fixed::units((int) $alloc[$l->id])->div(Fixed::units((int) $l->quantity)) : null,
                ])->all(),
                'costs' => $costs->map(fn ($c) => ['code' => $c->code, 'amount' => (string) Fixed::units((int) $c->amount), 'basis' => $c->basis, 'in_customs_value' => (bool) $c->in_customs_value])->all(),
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function purchaseOrders(): JsonResponse
    {
        $rows = DB::table('purchase_orders')->orderByDesc('id')->limit(300)->get()->map(function ($o) {
            $lines = DB::table('purchase_order_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.purchase_order_id', $o->id)->orderBy('l.id')->get(['p.sku', 'p.name', 'l.quantity', 'l.unit_price']);
            $total = Fixed::zero();
            foreach ($lines as $l) {
                $total = $total->add(Fixed::units((int) $l->quantity)->mul(Fixed::units((int) $l->unit_price))->mul(Fixed::units((int) $o->exchange_rate)));
            }

            return [
                'id' => (int) $o->id, 'number' => $o->number, 'supplier' => $o->supplier, 'currency' => $o->currency, 'status' => $o->status,
                'required_approval_level' => (int) $o->required_approval_level, 'created_by' => $o->created_by === null ? null : (int) $o->created_by, 'total' => (string) $total,
                'lines' => $lines->map(fn ($l) => ['sku' => $l->sku, 'name' => $l->name, 'quantity' => (string) Fixed::units((int) $l->quantity), 'unit_price' => (string) Fixed::units((int) $l->unit_price)])->all(),
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $showCost = $this->can($request, Permission::InventoryViewCost);
        $rows = DB::table('sales_orders as o')->join('customers as c', 'c.id', '=', 'o.customer_id')->orderByDesc('o.id')->limit(300)
            ->get(['o.id', 'o.number', 'o.status', 'o.warehouse_id', 'o.created_at', 'o.created_by', 'o.customer_name', 'c.name as customer'])
            ->filter(fn ($o) => $this->access->canUseWarehouse($userId, (int) $o->warehouse_id))->values()
            ->map(function ($o) use ($showCost) {
                $lines = DB::table('sales_order_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.sales_order_id', $o->id)->orderBy('l.id')
                    ->get(['l.id', 'p.sku', 'p.name', 'p.tracks_serials', 'l.quantity', 'l.list_price', 'l.discount_rate', 'l.unit_price', 'l.cost_of_goods']);
                $net = Fixed::zero();
                foreach ($lines as $l) {
                    $net = $net->add(Fixed::units((int) $l->quantity)->mul(Fixed::units((int) $l->unit_price)));
                }

                return [
                    'id' => (int) $o->id, 'number' => $o->number, 'status' => $o->status, 'customer' => $o->customer_name ?? $o->customer,
                    'invoice_id' => ($inv = DB::table('sales_invoices')->where('sales_order_id', $o->id)->value('id')) ? (int) $inv : null, 'warehouse_id' => (int) $o->warehouse_id,
                    'created_at' => $o->created_at, 'net' => (string) $net,
                    'lines' => $lines->map(fn ($l) => array_filter([
                        'id' => (int) $l->id, 'sku' => $l->sku, 'name' => $l->name, 'tracks_serials' => (bool) $l->tracks_serials,
                        'serials' => $l->tracks_serials ? DB::table('product_serials')->where('sales_order_line_id', $l->id)->orderBy('id')->pluck('serial')->all() : [],
                        'quantity' => (string) Fixed::units((int) $l->quantity),
                        'list_price' => (string) Fixed::units((int) $l->list_price), 'discount_rate' => (string) Fixed::units((int) $l->discount_rate), 'unit_price' => (string) Fixed::units((int) $l->unit_price),
                        'cost_of_goods' => $showCost && $l->cost_of_goods !== null ? (string) Fixed::units((int) $l->cost_of_goods) : null,
                    ], fn ($v) => $v !== null))->all(),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    public function lots(Request $request): JsonResponse
    {
        $f = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer']]);
        $userId = $this->userId($request);
        $showCost = $this->can($request, Permission::InventoryViewCost);
        $rows = DB::table('stock_lots as l')->join('products as p', 'p.id', '=', 'l.product_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->when(isset($f['warehouse_id']), fn ($q) => $q->where('l.warehouse_id', (int) $f['warehouse_id']))
            ->when(isset($f['product_id']), fn ($q) => $q->where('l.product_id', (int) $f['product_id']))
            ->where('l.on_hand', '>', 0)->orderBy('p.sku')->orderByRaw('l.expiry_date is null')->orderBy('l.expiry_date')->limit(1000)
            ->get(['p.sku', 'p.name', 'l.warehouse_id', 'w.code as warehouse', 'l.lot_no', 'l.expiry_date', 'l.on_hand', 'l.value'])
            ->filter(fn ($r) => $this->access->canUseWarehouse($userId, (int) $r->warehouse_id))->values()
            ->map(fn ($r) => array_filter([
                'sku' => $r->sku, 'name' => $r->name, 'warehouse' => $r->warehouse, 'lot_no' => $r->lot_no, 'expiry_date' => $r->expiry_date,
                'on_hand' => (string) Fixed::units((int) $r->on_hand),
                'unit_cost' => $showCost ? (string) Fixed::units((int) $r->value)->div(Fixed::units((int) $r->on_hand)) : null,
            ], fn ($v) => $v !== null));

        return response()->json(['data' => $rows]);
    }

    public function users(AccessRepository $access): JsonResponse
    {
        return response()->json(['data' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'active'])->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => (bool) $u->active,
            'roles' => $access->rolesOf($u->id), 'warehouses' => $access->warehousesOf($u->id),
        ])]);
    }

    public function updateProduct(Request $request, int $product, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'name' => ['string', 'max:200'], 'active' => ['boolean'], 'tracks_serials' => ['boolean'], 'has_expiry' => ['boolean'],
            'lead_time_days' => ['integer', 'between:0,365'], 'cover_days' => ['integer', 'between:1,365'],
        ]);
        abort_unless(DB::table('products')->where('id', $product)->exists(), 404);
        DB::transaction(function () use ($product, $d, $audit) {
            DB::table('products')->where('id', $product)->update($d + ['updated_at' => now()]);
            $audit->record('product.updated', 'product', $product, $d);
        });

        return response()->json(null, 204);
    }

    public function updateCustomer(Request $request, int $customer, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'name' => ['string', 'max:200'], 'category' => ['in:wholesale,retail,direct'],
            'credit_limit' => [Decimal::RULE], 'terms_days' => ['integer', 'between:0,365'],
            'tax_id' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:250'],
        ]);
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404);
        $values = $d;
        if (isset($d['credit_limit'])) {
            $values['credit_limit'] = Decimal::of($d['credit_limit'])->units;
        }
        DB::transaction(function () use ($customer, $values, $d, $audit) {
            DB::table('customers')->where('id', $customer)->update($values + ['updated_at' => now()]);
            $audit->record('customer.updated', 'customer', $customer, $d);
        });

        return response()->json(null, 204);
    }
}
