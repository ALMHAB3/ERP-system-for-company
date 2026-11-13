<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Reporting\Domain\DemandForecaster;
use Modules\Shared\Domain\Fixed;

/** Demand history -> forecast -> reorder point and suggested order quantity per product. */
final class PlanningService
{
    private const Z_95 = 1.65;

    public function __construct(private readonly ConnectionInterface $db, private readonly DemandForecaster $forecaster) {}

    /**
     * Net units sold per COMPLETED calendar month (issues minus customer returns), oldest first, zero-filled.
     * The current month is excluded: a half-finished month would read as a demand collapse.
     */
    public function monthlyDemand(int $productId, int $months = 36, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $start = $now->modify('first day of this month')->modify("-{$months} months");
        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $buckets[$start->modify("+{$i} months")->format('Y-m')] = 0;
        }

        $rows = $this->db->table('stock_moves')->where('product_id', $productId)->whereIn('type', ['issue', 'sales_return'])
            ->where('occurred_at', '>=', $start->format('Y-m-d 00:00:00'))->where('occurred_at', '<', $now->modify('first day of this month')->format('Y-m-d 00:00:00'))->get(['type', 'quantity', 'occurred_at']);
        foreach ($rows as $r) {
            $key = substr((string) $r->occurred_at, 0, 7);
            if (isset($buckets[$key])) {
                $buckets[$key] += $r->type === 'issue' ? (int) $r->quantity : -(int) $r->quantity;
            }
        }

        // Drop leading months before the first sale: they say "not stocked yet", not "zero demand".
        $values = array_values($buckets);
        while ($values !== [] && $values[0] === 0) {
            array_shift($values);
        }

        return array_map(static fn ($u) => $u / Fixed::FACTOR + 0.0, $values);
    }

    /** @return array<string,mixed> */
    public function forecast(int $productId, int $horizon = 3): array
    {
        $history = $this->monthlyDemand($productId);
        $result = $this->forecaster->forecast($history, $horizon);
        $last = (new DateTimeImmutable('first day of this month'))->modify('-1 month');   // last completed month
        $labels = [];
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $labels[] = $last->modify("-{$i} months")->format('Y-m');
        }
        $future = [];
        for ($i = 1; $i <= $horizon; $i++) {
            $future[] = $last->modify("+{$i} months")->format('Y-m');
        }

        return [
            'history' => array_map(static fn ($v) => round($v, 2), $history), 'history_labels' => $labels, 'forecast_labels' => $future,
            'product_id' => $productId, 'method' => $result->method, 'history_months' => $result->periods,
            'forecast' => array_map(static fn ($v) => round($v, 2), $result->forecast),
            'wape' => $result->wape === null ? null : round($result->wape, 3),
        ];
    }

    /**
     * Products whose stock position (on hand + on the way - reserved) cannot cover lead-time demand.
     *
     * @return list<array<string,mixed>>
     */
    public function reorderSuggestions(?int $warehouseId = null): array
    {
        $products = $this->db->table('products')->where('active', true)->orderBy('sku')->get(['id', 'sku', 'name', 'lead_time_days', 'cover_days']);
        $onHand = [];
        $reserved = [];
        foreach ($this->db->table('stock_balances')->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->get() as $b) {
            $onHand[$b->product_id] = ($onHand[$b->product_id] ?? 0) + (int) $b->on_hand;
            $reserved[$b->product_id] = ($reserved[$b->product_id] ?? 0) + (int) $b->reserved;
        }
        $incoming = [];
        foreach ($this->db->table('shipment_lines as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->whereIn('s.status', ['open', 'costed'])->get(['l.product_id', 'l.quantity']) as $r) {
            $incoming[$r->product_id] = ($incoming[$r->product_id] ?? 0) + (int) $r->quantity;
        }
        foreach ($this->db->table('purchase_order_lines as l')->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')->whereIn('p.status', ['pending_approval', 'approved'])->get(['l.product_id', 'l.quantity']) as $r) {
            $incoming[$r->product_id] = ($incoming[$r->product_id] ?? 0) + (int) $r->quantity;
        }

        $out = [];
        foreach ($products as $p) {
            $result = $this->forecaster->forecast($this->monthlyDemand((int) $p->id), 3);
            if ($result->periods === 0) {
                continue;   // never sold: nothing to plan from
            }
            $daily = array_sum($result->forecast) / (3 * 30);
            $sigmaDaily = $result->stdDev / sqrt(30);
            $lead = (int) $p->lead_time_days;
            $safety = self::Z_95 * $sigmaDaily * sqrt($lead);
            $rop = $daily * $lead + $safety;
            $position = (($onHand[$p->id] ?? 0) - ($reserved[$p->id] ?? 0) + ($incoming[$p->id] ?? 0)) / Fixed::FACTOR;
            if ($position > $rop) {
                continue;
            }
            $target = $daily * ($lead + (int) $p->cover_days) + $safety;
            $out[] = [
                'product_id' => (int) $p->id, 'sku' => $p->sku, 'name' => $p->name,
                'position' => round($position, 2), 'on_hand' => round(($onHand[$p->id] ?? 0) / Fixed::FACTOR, 2),
                'incoming' => round(($incoming[$p->id] ?? 0) / Fixed::FACTOR, 2),
                'daily_demand' => round($daily, 2), 'lead_time_days' => $lead, 'safety_stock' => round($safety, 1),
                'reorder_point' => round($rop, 1), 'suggested_quantity' => (int) ceil($target - $position),
                'days_of_cover' => $daily > 0 ? round($position / $daily, 1) : null,
                'method' => $result->method, 'wape' => $result->wape === null ? null : round($result->wape, 3),
            ];
        }
        usort($out, static fn ($a, $b) => ($a['days_of_cover'] ?? PHP_FLOAT_MAX) <=> ($b['days_of_cover'] ?? PHP_FLOAT_MAX));

        return $out;
    }
}
