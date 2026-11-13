<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Finance\Application\CashAccounts;
use Modules\Finance\Application\ExchangeRates;
use Modules\Procurement\Application\PayablesService;
use Modules\Procurement\Application\ShipmentSettlementService;
use Modules\Procurement\Domain\InvoiceKind;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class PayablesController extends ApiController
{
    public function storeSupplier(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200', 'unique:suppliers,name'],
            'country' => ['nullable', 'string', 'size:2'],
            'tax_no' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'terms_days' => ['integer', 'between:0,365'],
        ]);
        $id = DB::transaction(function () use ($d, $audit) {
            $id = DB::table('suppliers')->insertGetId(['name' => $d['name'], 'country' => isset($d['country']) ? strtoupper($d['country']) : null,
                'tax_no' => $d['tax_no'] ?? null, 'currency' => strtoupper($d['currency']), 'terms_days' => $d['terms_days'] ?? 30,
                'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $audit->record('supplier.created', 'supplier', $id, $d);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function suppliers(): JsonResponse
    {
        return response()->json(['data' => DB::table('suppliers')->orderBy('name')->get(['id', 'name', 'country', 'currency', 'terms_days', 'active'])]);
    }

    public function recordInvoice(Request $request, PayablesService $service, ExchangeRates $rates): JsonResponse
    {
        $d = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'number' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', Decimal::RULE],
            'subtotal' => ['required', Decimal::RULE],
            'tax' => ['nullable', Decimal::RULE],
            'kind' => ['required', 'in:goods,freight,customs,clearance,insurance,expense'],
            'shipment_reference' => ['nullable', 'string', 'exists:shipments,reference', 'prohibits:po_number'],
            'po_number' => ['nullable', 'string', 'exists:purchase_orders,number'],
            'expense_account' => ['nullable', 'string', 'exists:accounts,code'],
        ]);
        $reference = isset($d['shipment_reference']) ? 'shipment:'.$d['shipment_reference'] : (isset($d['po_number']) ? 'po:'.$d['po_number'] : null);
        if (DB::table('supplier_invoices')->where('supplier_id', $d['supplier_id'])->where('number', $d['number'])->exists()) {
            return response()->json(['message' => 'This supplier invoice number is already booked.', 'code' => 'DuplicateInvoice'], 422);
        }

        $id = $service->recordInvoice(
            $d['supplier_id'], $d['number'], new DateTimeImmutable($d['invoice_date']), $d['currency'], $rates->resolve($d['currency'], $d['exchange_rate'] ?? null, new DateTimeImmutable($d['invoice_date'])),
            Decimal::of($d['subtotal']), isset($d['tax']) ? Decimal::of($d['tax']) : Fixed::zero(), InvoiceKind::from($d['kind']),
            $reference, $d['expense_account'] ?? null, isset($d['due_date']) ? new DateTimeImmutable($d['due_date']) : null,
        );

        return response()->json(['id' => $id], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        $f = $request->validate(['supplier_id' => ['integer'], 'open' => ['boolean']]);
        $rows = DB::table('supplier_invoices as i')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->when(isset($f['supplier_id']), fn ($q) => $q->where('i.supplier_id', $f['supplier_id']))
            ->when(! empty($f['open']), fn ($q) => $q->whereRaw('i.subtotal + i.tax - i.paid > 0'))
            ->orderByDesc('i.id')->limit(500)
            ->get(['i.id', 'i.supplier_id', 's.name as supplier', 'i.number', 'i.invoice_date', 'i.due_date', 'i.currency', 'i.kind', 'i.receipt_reference', 'i.subtotal', 'i.tax', 'i.paid'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'supplier_id' => (int) $r->supplier_id, 'supplier' => $r->supplier, 'number' => $r->number,
                'invoice_date' => $r->invoice_date, 'due_date' => $r->due_date, 'currency' => $r->currency, 'kind' => $r->kind,
                'receipt_reference' => $r->receipt_reference, 'total' => (string) Fixed::units((int) $r->subtotal + (int) $r->tax),
                'balance' => (string) Fixed::units((int) $r->subtotal + (int) $r->tax - (int) $r->paid),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function pay(Request $request, PayablesService $service, ExchangeRates $rates, CashAccounts $cash): JsonResponse
    {
        $d = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', Decimal::RULE],
            'method' => ['required', 'in:cash,transfer,bank,cheque'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'reference' => ['required', 'string', 'max:120'],
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', Decimal::RULE],
        ]);
        $allocations = [];
        foreach ($d['allocations'] as $a) {
            $allocations[(int) $a['invoice_id']] = Decimal::of($a['amount']);
        }
        $id = $service->pay($d['supplier_id'], new DateTimeImmutable($d['paid_on']), $d['currency'], $rates->resolve($d['currency'], $d['exchange_rate'] ?? null, new DateTimeImmutable($d['paid_on'])), CashAccounts::normalize($d['method']), $d['reference'], $allocations,
            $cash->resolve($d['method'], isset($d['bank_account_id']) ? (int) $d['bank_account_id'] : null));

        return response()->json(['id' => $id], 201);
    }

    public function receiptStatus(Request $request, PayablesService $service): JsonResponse
    {
        $d = $request->validate(['reference' => ['required', 'string', 'regex:/^(shipment|po):.+$/']]);

        return response()->json($service->receiptStatus($d['reference']));
    }

    public function clearReceipt(Request $request, PayablesService $service): JsonResponse
    {
        $d = $request->validate(['reference' => ['required', 'string', 'regex:/^(shipment|po):.+$/'], 'reason' => ['required', 'string', 'min:5', 'max:200']]);

        return response()->json(['cleared' => (string) $service->clearToVariance($d['reference'], $d['reason'])]);
    }

    /** Preview (GET) or book (POST) the final actual cost of a fully paid shipment. */
    public function settlement(Request $request, ShipmentSettlementService $service): JsonResponse
    {
        $d = $request->validate(['shipment_reference' => ['required', 'string', 'exists:shipments,reference']]);
        $reference = 'shipment:'.$d['shipment_reference'];
        $plan = $request->isMethod('POST') ? $service->settle($reference, $this->userId($request)) : $service->preview($reference);

        $products = DB::table('products')->whereIn('id', array_column($plan['lines'], 'product_id'))->pluck('sku', 'id');
        $money = fn (Fixed $v) => (string) $v;

        return response()->json([
            'receipt_reference' => $plan['receipt_reference'], 'settled' => $request->isMethod('POST'),
            'booked_total' => $money($plan['booked_total']), 'fx_difference' => $money($plan['fx_difference']), 'cleared_variance' => $money($plan['cleared_variance']),
            'delta' => $money($plan['delta']), 'final_total' => $money($plan['booked_total']->add($plan['delta'])),
            'inventory_part' => $money($plan['inventory_part']), 'cogs_part' => $money($plan['cogs_part']),
            'lines' => array_map(fn ($l) => [
                'sku' => $products[$l['product_id']] ?? $l['product_id'], 'lot_no' => $l['lot_no'], 'received_quantity' => $money($l['received_quantity']), 'quantity_in_stock' => $money($l['quantity_in_stock']),
                'booked_cost' => $money($l['booked_cost']), 'delta' => $money($l['delta']), 'inventory_part' => $money($l['inventory_part']), 'cogs_part' => $money($l['cogs_part']),
                'final_unit_cost' => $money($l['booked_cost']->add($l['delta'])->div($l['received_quantity'])),
            ], $plan['lines']),
        ], $request->isMethod('POST') ? 201 : 200);
    }

    public function ageing(Request $request): JsonResponse
    {
        $asOf = new DateTimeImmutable($request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? 'today');
        $buckets = array_fill_keys(['current', '1-30', '31-60', '61-90', '90+'], 0);
        $suppliers = [];
        $rows = DB::table('supplier_invoices as i')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->whereRaw('i.base_total - i.base_paid > 0')->orderBy('s.name')->orderBy('i.due_date')
            ->get(['s.id as supplier_id', 's.name', 'i.number', 'i.due_date', 'i.base_total', 'i.base_paid']);
        foreach ($rows as $r) {
            $balance = (int) $r->base_total - (int) $r->base_paid;
            $late = max(0, (int) (new DateTimeImmutable($r->due_date))->diff($asOf)->format('%r%a'));
            $bucket = match (true) {
                $late === 0 => 'current', $late <= 30 => '1-30', $late <= 60 => '31-60', $late <= 90 => '61-90', default => '90+'
            };
            $buckets[$bucket] += $balance;
            $suppliers[$r->supplier_id]['supplier'] = $r->name;
            $suppliers[$r->supplier_id]['total'] = ($suppliers[$r->supplier_id]['total'] ?? 0) + $balance;
            $suppliers[$r->supplier_id]['invoices'][] = ['number' => $r->number, 'due_date' => $r->due_date, 'balance' => (string) Fixed::units($balance), 'days_overdue' => $late];
        }

        return response()->json([
            'currency' => config('erp.base_currency'),
            'buckets' => array_map(fn ($u) => (string) Fixed::units($u), $buckets),
            'suppliers' => array_values(array_map(fn ($s) => ['supplier' => $s['supplier'], 'total' => (string) Fixed::units($s['total']), 'invoices' => $s['invoices']], $suppliers)),
        ]);
    }
}
