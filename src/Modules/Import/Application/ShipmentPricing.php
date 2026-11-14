<?php

declare(strict_types=1);

namespace Modules\Import\Application;

use Illuminate\Support\Facades\DB;

/** The selling price typed with a shipment line becomes the product's price in every customer list once the goods are received. */
final class ShipmentPricing
{
    private const LISTS = [null, 'wholesale', 'retail', 'direct'];

    public function apply(int $shipmentId): int
    {
        $n = 0;
        foreach (DB::table('shipment_lines')->where('shipment_id', $shipmentId)->whereNotNull('sale_price')->get(['product_id', 'sale_price']) as $l) {
            foreach (self::LISTS as $category) {
                DB::table('price_list_items')->updateOrInsert(['product_id' => $l->product_id, 'category' => $category], ['price' => (int) $l->sale_price]);
            }
            $n++;
        }

        return $n;
    }
}
