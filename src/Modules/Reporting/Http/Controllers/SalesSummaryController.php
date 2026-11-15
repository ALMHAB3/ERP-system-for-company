<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;
use Modules\Shared\Http\ApiController;

/** What was sold today / in the last 7 or 30 days, by sales representative and payment method (for managers). */
final class SalesSummaryController extends ApiController
{
    private const DAYS = ['today' => 1, 'week' => 7, 'month' => 30];

    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['in:today,week,month']])['period'] ?? 'today';
        $from = CarbonImmutable::today()->subDays(self::DAYS[$period] - 1)->startOfDay();
        $rows = DB::table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->when(($branches = $this->branchScope($request)) !== null, fn ($q) => $q->whereIn('o.warehouse_id', $branches === [] ? [0] : $branches))
            ->where('i.created_at', '>=', $from)->orderByDesc('i.id')
            ->get(['i.id as invoice_id', 'i.number', 'i.net', 'i.tax', 'i.gross', 'i.created_at', 'o.payment_method', 'o.customer_name', 'c.name as customer', 'u.name as rep']);
        $sum = fn ($items, string $k) => (string) Fixed::units((int) $items->sum(fn ($r) => (int) $r->$k));
        $group = fn (string $key) => $rows->groupBy(fn ($r) => $r->$key ?? '—')->map(fn ($items, $name) => ['name' => (string) $name, 'count' => $items->count(), 'gross' => $sum($items, 'gross')])->values()->all();

        return response()->json([
            'period' => $period, 'from' => $from->format('Y-m-d'),
            'totals' => ['count' => $rows->count(), 'net' => $sum($rows, 'net'), 'tax' => $sum($rows, 'tax'), 'gross' => $sum($rows, 'gross')],
            'by_rep' => $group('rep'), 'by_method' => $group('payment_method'),
            'invoices' => $rows->take(500)->map(fn ($r) => [
                'invoice_id' => (int) $r->invoice_id, 'number' => $r->number, 'issued' => $r->created_at, 'customer' => $r->customer_name ?? $r->customer,
                'rep' => $r->rep, 'payment_method' => $r->payment_method, 'gross' => (string) Fixed::units((int) $r->gross),
            ])->all(),
        ]);
    }
}
