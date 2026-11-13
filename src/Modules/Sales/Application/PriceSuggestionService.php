<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Modules\Audit\Application\AuditLogger;
use Modules\Sales\Domain\CustomerCategory;
use Modules\Sales\Domain\Pricing\PriceRounder;
use Modules\Shared\Domain\Fixed;

/**
 * Suggested selling price = unit cost in base currency x (1 + margin), rounded UP to the price step.
 * Costs stay exact in the books; rounding only ever shapes the price list, and only when a person applies it.
 */
final class PriceSuggestionService
{
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditLogger $audit) {}

    public function rounder(): PriceRounder
    {
        return new PriceRounder(Fixed::parse((string) config('erp.pricing.rounding_step')));
    }

    /**
     * @param  string  $basis  'last' = unit cost of the latest receipt (replacement cost), 'average' = value / quantity on hand
     * @return list<array<string,mixed>>
     */
    public function suggest(CustomerCategory $category, Fixed $marginPct, string $basis): array
    {
        $rounder = $this->rounder();
        $rate = $marginPct->div(Fixed::of(100));

        $stock = $this->db->table('stock_balances')->groupBy('product_id')->selectRaw('product_id, sum(on_hand) as qty, sum(value) as value')->get()->keyBy('product_id');
        $lastCost = [];
        foreach ($this->db->table('stock_moves')->where('type', 'receipt')->orderBy('id')->get(['product_id', 'unit_cost']) as $m) {
            $lastCost[$m->product_id] = (int) $m->unit_cost;     // ascending id: the last assignment wins
        }
        $lastBuy = [];
        foreach ($this->db->table('shipment_lines as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->orderBy('l.id')->get(['l.product_id', 'l.unit_price', 's.currency', 's.exchange_rate', 's.reference']) as $l) {
            $lastBuy[$l->product_id] = ['price' => (string) Fixed::units((int) $l->unit_price), 'currency' => $l->currency, 'rate' => (string) Fixed::units((int) $l->exchange_rate), 'shipment' => $l->reference];
        }
        $current = $this->db->table('price_list_items')->where('category', $category->value)->pluck('price', 'product_id');

        $out = [];
        foreach ($this->db->table('products')->where('active', true)->orderBy('sku')->get(['id', 'sku', 'name']) as $p) {
            $qty = (int) ($stock[$p->id]->qty ?? 0);
            $cost = $basis === 'average'
                ? ($qty > 0 ? Fixed::units((int) $stock[$p->id]->value)->div(Fixed::units($qty)) : null)
                : (isset($lastCost[$p->id]) ? Fixed::units($lastCost[$p->id]) : null);
            if ($cost === null) {
                continue;     // never bought: nothing to price from
            }
            $raw = $cost->add($cost->mul($rate));
            $suggested = $rounder->up($raw);
            $out[] = [
                'product_id' => (int) $p->id, 'sku' => $p->sku, 'name' => $p->name, 'on_hand' => (string) Fixed::units($qty),
                'unit_cost' => (string) $cost, 'last_purchase' => $lastBuy[$p->id] ?? null,
                'raw_price' => (string) $raw, 'suggested_price' => (string) $suggested,
                'current_price' => isset($current[$p->id]) ? (string) Fixed::units((int) $current[$p->id]) : null,
                'margin_after_rounding_pct' => $cost->isPositive() ? round(($suggested->units - $cost->units) / $cost->units * 100, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * Writes chosen prices into the category price list. Each price is re-rounded server-side, so a hand-typed 353 is stored as 355.
     *
     * @param  array<int,Fixed>  $prices  product id => price
     */
    public function apply(CustomerCategory $category, array $prices): int
    {
        if ($prices === []) {
            throw new DomainException('Choose at least one price to apply.');
        }
        $rounder = $this->rounder();

        return $this->db->transaction(function () use ($category, $prices, $rounder) {
            foreach ($prices as $productId => $price) {
                if (! $price->isPositive() || ! $this->db->table('products')->where('id', $productId)->exists()) {
                    throw new DomainException("Invalid price or product #{$productId}.");
                }
                $this->db->table('price_list_items')->updateOrInsert(['product_id' => $productId, 'category' => $category->value], ['price' => $rounder->up($price)->units]);
            }
            $this->audit->record('price.suggestions_applied', 'price_list', $category->value, ['products' => array_map(fn (Fixed $p) => (string) $p, $prices), 'step' => (string) $rounder->step()]);

            return count($prices);
        });
    }
}
