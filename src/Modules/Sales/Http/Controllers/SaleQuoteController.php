<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Domain\CustomerRepository;
use Modules\Sales\Domain\Pricing\PricingContext;
use Modules\Sales\Domain\Pricing\PricingEngineFactory;
use Modules\Shared\Http\ApiController;
use Modules\Shared\Http\Decimal;

/** The price a product would be sold at right now (shown line by line while the sale is typed). */
final class SaleQuoteController extends ApiController
{
    public function __invoke(Request $request, PricingEngineFactory $pricing, CustomerRepository $customers): JsonResponse
    {
        $d = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', Decimal::RULE], 'customer_id' => ['nullable', 'integer', 'exists:customers,id']]);
        $warehouse = (int) $d['warehouse_id'];
        $this->warehouse($request, $warehouse);
        $customerId = ! empty($d['customer_id']) ? (int) $d['customer_id'] : (int) DB::table('customers')->where('is_walk_in', true)->value('id');
        $customer = $customers->find($customerId) ?? abort(404);
        $q = $pricing->forCategory($customer->category)->quote(new PricingContext($customer->id, $customer->category, (int) $d['product_id'], $warehouse, Decimal::of($d['quantity'])));

        return response()->json(['unit_price' => (string) $q->unitPrice, 'list_price' => (string) $q->listPrice, 'discount_rate' => (string) $q->discountRate]);
    }
}
