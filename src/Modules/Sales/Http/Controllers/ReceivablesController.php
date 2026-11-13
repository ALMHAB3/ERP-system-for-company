<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Application\ReceivablesService;
use Modules\Sales\Application\SalesReturnService;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

final class ReceivablesController extends ApiController
{
    public function receive(Request $request, ReceivablesService $service): JsonResponse
    {
        $d = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'in:bank,cash,cheque'],
            'reference' => ['required', 'string', 'max:120'],
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', Decimal::RULE],
        ]);
        $allocations = [];
        foreach ($d['allocations'] as $a) {
            $allocations[(int) $a['invoice_id']] = Decimal::of($a['amount']);
        }

        return response()->json(['id' => $service->receive($d['customer_id'], new DateTimeImmutable($d['received_on']), $d['method'], $d['reference'], $allocations)], 201);
    }

    public function creditNote(Request $request, int $order, SalesReturnService $service): JsonResponse
    {
        $d = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:200'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', Decimal::RULE],
        ]);
        $warehouse = (int) DB::table('sales_orders')->where('id', $order)->value('warehouse_id') ?: abort(404);
        $this->warehouse($request, $warehouse);
        $quantities = [];
        foreach ($d['lines'] as $l) {
            $quantities[(int) $l['line_id']] = Decimal::of($l['quantity']);
        }

        return response()->json(['credit_note_id' => $service->returnGoods($order, $quantities, $d['reason'])], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        $f = $request->validate(['customer_id' => ['integer'], 'open' => ['boolean']]);
        $rows = DB::table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->when(isset($f['customer_id']), fn ($q) => $q->where('o.customer_id', $f['customer_id']))
            ->when(! empty($f['open']), fn ($q) => $q->whereRaw('i.gross - i.paid - i.credited > 0'))
            ->orderByDesc('i.id')->limit(500)
            ->get(['i.id', 'i.number', 'c.id as customer_id', 'c.name as customer', 'o.number as order_number', 'i.due_date', 'i.net', 'i.tax', 'i.gross', 'i.paid', 'i.credited', 'i.created_at'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'number' => $r->number, 'customer_id' => (int) $r->customer_id, 'customer' => $r->customer, 'order' => $r->order_number,
                'issued' => $r->created_at, 'due_date' => $r->due_date, 'net' => (string) Fixed::units((int) $r->net), 'tax' => (string) Fixed::units((int) $r->tax),
                'gross' => (string) Fixed::units((int) $r->gross), 'paid' => (string) Fixed::units((int) $r->paid), 'credited' => (string) Fixed::units((int) $r->credited),
                'balance' => (string) Fixed::units((int) $r->gross - (int) $r->paid - (int) $r->credited),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function ageing(Request $request, ReceivablesService $service): JsonResponse
    {
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? 'today';

        return response()->json(['currency' => config('erp.base_currency')] + $service->ageing(new DateTimeImmutable($asOf)));
    }
}
