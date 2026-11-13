<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Shared\Domain\Fixed;

/** Read-side queries for management reports. Money is reported in base currency, never rounded away. */
final class ReportQueries
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string,mixed> */
    public function dashboard(DateTimeImmutable $now): array
    {
        $since = $now->modify('-365 days')->format('Y-m-d H:i:s');
        $inventoryValue = (int) $this->db->table('stock_balances')->sum('value');
        $cogsYear = (int) $this->db->table('stock_moves')->where('type', 'issue')->where('occurred_at', '>=', $since)->sum('total_cost')
            - (int) $this->db->table('stock_moves')->where('type', 'sales_return')->where('occurred_at', '>=', $since)->sum('total_cost');
        $turnover = $inventoryValue > 0 ? $cogsYear / $inventoryValue : null;

        $revenueMonth = (int) $this->db->table('sales_invoices')->where('created_at', '>=', $now->modify('first day of this month')->format('Y-m-d 00:00:00'))->sum('net')
            - (int) $this->db->table('credit_notes')->where('created_at', '>=', $now->modify('first day of this month')->format('Y-m-d 00:00:00'))->sum('net');

        $shipments = $this->db->table('shipments')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $expiring = $this->expiring(60, $now);
        $receivable = (int) $this->db->table('sales_invoices')->selectRaw('COALESCE(SUM(gross - paid - credited),0) as v')->value('v');
        $payable = (int) $this->db->table('supplier_invoices')->selectRaw('COALESCE(SUM(base_total - base_paid),0) as v')->value('v');

        return [
            'currency' => config('erp.base_currency'),
            'inventory_value' => (string) Fixed::units($inventoryValue),
            'inventory_turnover' => $turnover === null ? null : round($turnover, 2),
            'days_of_inventory' => $turnover ? round(365 / $turnover, 1) : null,
            'revenue_this_month' => (string) Fixed::units($revenueMonth),
            'receivables_open' => (string) Fixed::units($receivable),
            'payables_open' => (string) Fixed::units($payable),
            'shipments' => ['open' => (int) ($shipments['open'] ?? 0), 'costed' => (int) ($shipments['costed'] ?? 0), 'received' => (int) ($shipments['received'] ?? 0)],
            'expiring_60d_value' => (string) Fixed::units(array_sum(array_map(static fn ($r) => Fixed::parse($r['value'])->units, $expiring))),
            'expiring_60d_lots' => count($expiring),
            'orders_waiting_shipment' => (int) $this->db->table('sales_orders')->where('status', 'reserved')->count(),
        ];
    }

    /** Lots expiring within $days (or already expired), with the value at risk. @return list<array<string,mixed>> */
    public function expiring(int $days, DateTimeImmutable $now): array
    {
        return $this->db->table('stock_lots as l')->join('products as p', 'p.id', '=', 'l.product_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.on_hand', '>', 0)->whereNotNull('l.expiry_date')->where('l.expiry_date', '<=', $now->modify("+{$days} days")->format('Y-m-d'))
            ->orderBy('l.expiry_date')->limit(500)
            ->get(['p.sku', 'p.name', 'w.code as warehouse', 'l.lot_no', 'l.expiry_date', 'l.on_hand', 'l.value'])
            ->map(static fn ($r) => [
                'sku' => $r->sku, 'name' => $r->name, 'warehouse' => $r->warehouse, 'lot_no' => $r->lot_no, 'expiry_date' => $r->expiry_date,
                'expired' => $r->expiry_date < $now->format('Y-m-d'),
                'on_hand' => (string) Fixed::units((int) $r->on_hand), 'value' => (string) Fixed::units((int) $r->value),
            ])->all();
    }

    /** Stock that has not moved out for $days. @return list<array<string,mixed>> */
    public function slowMoving(int $days, DateTimeImmutable $now): array
    {
        $cutoff = $now->modify("-{$days} days")->format('Y-m-d H:i:s');
        $rows = $this->db->table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->where('b.on_hand', '>', 0)->orderByDesc('b.value')->get(['p.id', 'p.sku', 'p.name', 'b.on_hand', 'b.value']);
        $lastIssue = $this->db->table('stock_moves')->where('type', 'issue')->selectRaw('product_id, max(occurred_at) as last_issue')->groupBy('product_id')->pluck('last_issue', 'product_id');
        $out = [];
        foreach ($rows as $r) {
            $last = $lastIssue[$r->id] ?? null;
            if ($last === null || $last < $cutoff) {
                $out[] = ['sku' => $r->sku, 'name' => $r->name, 'on_hand' => (string) Fixed::units((int) $r->on_hand), 'value' => (string) Fixed::units((int) $r->value), 'last_sale' => $last];
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function productProfitability(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->table('sales_issue_lines as i')->join('sales_order_lines as l', 'l.id', '=', 'i.sales_order_line_id')
            ->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('o.status', ['shipped', 'invoiced'])->whereBetween('o.updated_at', [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')])
            ->get(['p.id', 'p.sku', 'p.name', 'i.quantity', 'i.cost', 'l.unit_price']);
        $agg = [];
        foreach ($rows as $r) {
            $revenue = Fixed::units((int) $r->quantity)->mul(Fixed::units((int) $r->unit_price))->units;
            $a = &$agg[$r->id];
            $a ??= ['sku' => $r->sku, 'name' => $r->name, 'quantity' => 0, 'revenue' => 0, 'cost' => 0];
            $a['quantity'] += (int) $r->quantity;
            $a['revenue'] += $revenue;
            $a['cost'] += (int) $r->cost;
            unset($a);
        }
        $out = array_map(static function ($a) {
            $profit = $a['revenue'] - $a['cost'];

            return [
                'sku' => $a['sku'], 'name' => $a['name'], 'quantity' => (string) Fixed::units($a['quantity']), 'revenue' => (string) Fixed::units($a['revenue']),
                'cogs' => (string) Fixed::units($a['cost']), 'profit' => (string) Fixed::units($profit),
                'margin_pct' => $a['revenue'] > 0 ? round($profit / $a['revenue'] * 100, 1) : null, '_p' => $profit,
            ];
        }, array_values($agg));
        usort($out, static fn ($x, $y) => $y['_p'] <=> $x['_p']);

        return array_map(static function ($r) {
            unset($r['_p']);

            return $r;
        }, $out);
    }

    /**
     * Profit per import shipment: what its lots sold for minus the landed cost of what was sold,
     * after returns, plus what is still unsold (valued at landed cost).
     *
     * @return list<array<string,mixed>>
     */
    public function shipmentProfitability(): array
    {
        // (product, warehouse, lot) -> shipment reference, from the receipt moves each shipment produced
        $lotOwner = [];
        foreach ($this->db->table('stock_moves')->where('type', 'receipt')->where('reference', 'like', 'shipment:%')->get(['product_id', 'warehouse_id', 'lot_no', 'reference', 'total_cost', 'quantity']) as $m) {
            $lotOwner["{$m->product_id}|{$m->warehouse_id}|{$m->lot_no}"] = $m->reference;
        }
        $report = [];
        foreach ($this->db->table('stock_moves')->where('type', 'receipt')->where('reference', 'like', 'shipment:%')->get(['reference', 'total_cost', 'quantity']) as $m) {
            $r = &$report[$m->reference];
            $r ??= ['received_qty' => 0, 'landed_cost' => 0, 'sold_qty' => 0, 'revenue' => 0, 'cogs' => 0, 'returned_cogs' => 0, 'returned_revenue' => 0];
            $r['received_qty'] += (int) $m->quantity;
            $r['landed_cost'] += (int) $m->total_cost;
            unset($r);
        }

        foreach ($this->db->table('sales_issue_lines as i')->join('sales_order_lines as l', 'l.id', '=', 'i.sales_order_line_id')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->join('stock_moves as m', 'm.id', '=', 'i.stock_move_id')->get(['m.product_id', 'm.warehouse_id', 'i.lot_no', 'i.quantity', 'i.cost', 'l.unit_price', 'i.id']) as $s) {
            $ref = $lotOwner["{$s->product_id}|{$s->warehouse_id}|{$s->lot_no}"] ?? null;
            if ($ref === null) {
                continue;
            }
            $report[$ref]['sold_qty'] += (int) $s->quantity;
            $report[$ref]['cogs'] += (int) $s->cost;
            $report[$ref]['revenue'] += Fixed::units((int) $s->quantity)->mul(Fixed::units((int) $s->unit_price))->units;
            foreach ($this->db->table('sales_return_lines')->where('sales_issue_line_id', $s->id)->get(['quantity', 'cost']) as $ret) {
                $report[$ref]['returned_cogs'] += (int) $ret->cost;
                $report[$ref]['returned_revenue'] += Fixed::units((int) $ret->quantity)->mul(Fixed::units((int) $s->unit_price))->units;
                $report[$ref]['sold_qty'] -= (int) $ret->quantity;
            }
        }

        $out = [];
        foreach ($report as $reference => $r) {
            $revenue = $r['revenue'] - $r['returned_revenue'];
            $cogs = $r['cogs'] - $r['returned_cogs'];
            $out[] = [
                'shipment' => substr($reference, 9), 'received_qty' => (string) Fixed::units($r['received_qty']), 'sold_qty' => (string) Fixed::units($r['sold_qty']),
                'sell_through_pct' => $r['received_qty'] > 0 ? round($r['sold_qty'] / $r['received_qty'] * 100, 1) : null,
                'landed_cost' => (string) Fixed::units($r['landed_cost']), 'revenue' => (string) Fixed::units($revenue),
                'cogs' => (string) Fixed::units($cogs), 'profit' => (string) Fixed::units($revenue - $cogs),
                'margin_pct' => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 1) : null,
                'unsold_stock_cost' => (string) Fixed::units($r['landed_cost'] - $cogs),
            ];
        }

        return $out;
    }
}
