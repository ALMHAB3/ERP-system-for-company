<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Domain\Fixed;

/**
 * Serial numbers for products flagged tracks_serials. The quantity ledger stays the source of truth for HOW MANY;
 * serials say WHICH units. Invariant: in-stock + reserved serials of a product in a warehouse never exceed its on-hand quantity.
 */
final class SerialService
{
    /** @param list<string> $serials */
    public function register(int $productId, int $warehouseId, array $serials): int
    {
        $serials = $this->clean($serials);

        return DB::transaction(function () use ($productId, $warehouseId, $serials): int {
            $this->requireTracked($productId);
            $dupes = DB::table('product_serials')->where('product_id', $productId)->whereIn('serial', $serials)->pluck('serial')->all();
            if ($dupes !== []) {
                throw new DomainException('Serial number already registered: '.implode(', ', array_slice($dupes, 0, 5)));
            }
            DB::table('stock_balances')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->lockForUpdate()->get();
            $onHand = (int) DB::table('stock_balances')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('on_hand');
            $held = DB::table('product_serials')->where('product_id', $productId)->where('warehouse_id', $warehouseId)->whereIn('status', ['in_stock', 'reserved'])->count();
            if (($held + count($serials)) * Fixed::FACTOR > $onHand) {
                throw new DomainException('More serial numbers than units in stock: on hand '.Fixed::units($onHand).', already registered '.$held.', adding '.count($serials).'.');
            }
            DB::table('product_serials')->insert(array_map(fn ($s) => ['product_id' => $productId, 'serial' => $s, 'warehouse_id' => $warehouseId, 'status' => 'in_stock', 'created_at' => now()], $serials));

            return count($serials);
        });
    }

    /** Pins serials to an order line (status reserved). Replaces any earlier choice for that line. @param list<string> $serials */
    public function assign(int $orderId, int $lineId, array $serials): void
    {
        $serials = $this->clean($serials);
        DB::transaction(function () use ($orderId, $lineId, $serials): void {
            $line = DB::table('sales_order_lines as l')->join('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
                ->where('l.id', $lineId)->where('o.id', $orderId)->lockForUpdate()->first(['l.product_id', 'l.quantity', 'o.warehouse_id', 'o.status']);
            if ($line === null || $line->status !== 'reserved') {
                throw new DomainException('Serials can only be chosen for a line of a reserved (not yet shipped) order.');
            }
            $this->requireTracked((int) $line->product_id);
            if (count($serials) * Fixed::FACTOR > (int) $line->quantity) {
                throw new DomainException('More serial numbers than the ordered quantity.');
            }
            $this->releaseLine($lineId);
            $rows = DB::table('product_serials')->where('product_id', $line->product_id)->whereIn('serial', $serials)->lockForUpdate()->get();
            foreach ($serials as $s) {
                $r = $rows->firstWhere('serial', $s);
                if ($r === null || $r->status !== 'in_stock' || (int) $r->warehouse_id !== (int) $line->warehouse_id) {
                    throw new DomainException("Serial {$s} is not available in this order's warehouse.");
                }
            }
            DB::table('product_serials')->whereIn('id', $rows->pluck('id'))->update(['status' => 'reserved', 'sales_order_line_id' => $lineId]);
        });
    }

    /** Called when an order ships: every tracked line must name exactly its quantity of serials. */
    public function sellForOrder(int $orderId): void
    {
        $lines = DB::table('sales_order_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.sales_order_id', $orderId)->where('p.tracks_serials', true)->get(['l.id', 'l.quantity', 'p.sku']);
        foreach ($lines as $l) {
            $n = DB::table('product_serials')->where('sales_order_line_id', $l->id)->where('status', 'reserved')->count();
            if ($n * Fixed::FACTOR !== (int) $l->quantity) {
                throw new DomainException("Choose the serial numbers of {$l->sku} before shipping: need ".Fixed::units((int) $l->quantity).", chosen {$n}.");
            }
        }
        if ($lines->isNotEmpty()) {
            DB::table('product_serials')->whereIn('sales_order_line_id', $lines->pluck('id'))->where('status', 'reserved')->update(['status' => 'sold', 'sold_at' => now()]);
        }
    }

    public function releaseForOrder(int $orderId): void
    {
        $ids = DB::table('sales_order_lines')->where('sales_order_id', $orderId)->pluck('id');
        DB::table('product_serials')->whereIn('sales_order_line_id', $ids)->where('status', 'reserved')->update(['status' => 'in_stock', 'sales_order_line_id' => null]);
    }

    /** A returned unit goes back on the shelf (the quantity side is booked by the credit note). @param list<string> $serials */
    public function returnToStock(int $productId, array $serials, int $warehouseId): int
    {
        return DB::transaction(fn () => DB::table('product_serials')->where('product_id', $productId)->whereIn('serial', $this->clean($serials))->where('status', 'sold')
            ->update(['status' => 'in_stock', 'warehouse_id' => $warehouseId, 'sales_order_line_id' => null, 'sold_at' => null]));
    }

    /** @return list<array<string,mixed>> where a serial is / where it went (customer, invoice) */
    public function trace(string $serial): array
    {
        return DB::table('product_serials as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('sales_order_lines as l', 'l.id', '=', 's.sales_order_line_id')->leftJoin('sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')->leftJoin('sales_invoices as i', 'i.sales_order_id', '=', 'o.id')
            ->where('s.serial', trim($serial))->get(['s.serial', 's.status', 's.sold_at', 'p.sku', 'p.name as product', 'w.name as warehouse', 'o.number as order_number', 'c.name as customer', 'i.number as invoice'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function releaseLine(int $lineId): void
    {
        DB::table('product_serials')->where('sales_order_line_id', $lineId)->where('status', 'reserved')->update(['status' => 'in_stock', 'sales_order_line_id' => null]);
    }

    private function requireTracked(int $productId): void
    {
        if (! DB::table('products')->where('id', $productId)->value('tracks_serials')) {
            throw new DomainException('This product does not track serial numbers (turn it on in the product settings first).');
        }
    }

    /** @param list<string> $serials @return list<string> */
    private function clean(array $serials): array
    {
        $out = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $serials), fn ($s) => $s !== '')));
        if ($out === [] || count($out) > 500) {
            throw new DomainException('Provide between 1 and 500 serial numbers.');
        }
        foreach ($out as $s) {
            if (! preg_match('/^[A-Za-z0-9\-_.\/]{1,80}$/', $s)) {
                throw new DomainException("Invalid serial number format: {$s}");
            }
        }

        return $out;
    }
}
