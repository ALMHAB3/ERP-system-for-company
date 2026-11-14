<?php

declare(strict_types=1);

namespace Modules\Integrations\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only CSV feeds for spreadsheets and accounting software (UTF-8 with BOM so Excel shows Arabic correctly). */
final class ExportController
{
    public function invoices(Request $request): StreamedResponse
    {
        $f = $request->validate(['from' => ['date_format:Y-m-d'], 'to' => ['date_format:Y-m-d']]);
        $q = DB::table('sales_invoices as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->when(isset($f['from']), fn ($q) => $q->where('i.created_at', '>=', $f['from'].' 00:00:00'))->when(isset($f['to']), fn ($q) => $q->where('i.created_at', '<=', $f['to'].' 23:59:59'))
            ->orderBy('i.id')->select(['i.number', 'i.created_at', 'o.customer_name', 'c.name as customer', 'c.tax_id', 'o.payment_method', 'i.net', 'i.tax', 'i.gross', 'i.paid', 'i.credited', 'i.fingerprint']);

        return $this->csv('invoices', ['number', 'issued', 'customer', 'customer_tax_id', 'payment_method', 'net', 'tax', 'gross', 'paid', 'credited', 'fingerprint'], $q,
            fn ($r) => [$r->number, $r->created_at, $r->customer_name ?? $r->customer, $r->tax_id, $r->payment_method, ...array_map(fn ($u) => (string) Fixed::units((int) $u), [$r->net, $r->tax, $r->gross, $r->paid, $r->credited]), $r->fingerprint]);
    }

    public function products(): StreamedResponse
    {
        $q = DB::table('products as p')->leftJoin('stock_balances as b', 'b.product_id', '=', 'p.id')->groupBy('p.id', 'p.sku', 'p.name', 'p.base_uom', 'p.active', 'p.tracks_serials')->orderBy('p.sku')
            ->select(['p.sku', 'p.name', 'p.base_uom', 'p.active', 'p.tracks_serials', DB::raw('COALESCE(SUM(b.on_hand),0) as on_hand'), DB::raw('COALESCE(SUM(b.reserved),0) as reserved')]);

        return $this->csv('products', ['sku', 'name', 'uom', 'active', 'tracks_serials', 'on_hand', 'reserved'], $q,
            fn ($r) => [$r->sku, $r->name, $r->base_uom, (int) $r->active, (int) $r->tracks_serials, (string) Fixed::units((int) $r->on_hand), (string) Fixed::units((int) $r->reserved)]);
    }

    public function customers(): StreamedResponse
    {
        $q = DB::table('customers')->orderBy('id')->select(['name', 'category', 'tax_id', 'address', 'credit_limit', 'terms_days']);

        return $this->csv('customers', ['name', 'category', 'tax_id', 'address', 'credit_limit', 'terms_days'], $q,
            fn ($r) => [$r->name, $r->category, $r->tax_id, $r->address, (string) Fixed::units((int) $r->credit_limit), $r->terms_days]);
    }

    private function csv(string $name, array $header, Builder $query, callable $row): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $query, $row) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($query->cursor() as $r) {
                // neutralise spreadsheet formula injection in text cells
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) && ! is_numeric($v) ? "'".$v : $v, $row($r)));
            }
            fclose($out);
        }, "{$name}-".now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
