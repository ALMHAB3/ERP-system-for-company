<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;
use Modules\Shared\Http\Decimal;

final class CustomerController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'in:wholesale,retail,direct'],
            'credit_limit' => [Decimal::RULE],
            'terms_days' => ['integer', 'between:0,365'],
        ]);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('customers')->insertGetId([
                'name' => $d['name'], 'category' => $d['category'],
                'credit_limit' => isset($d['credit_limit']) ? Decimal::of($d['credit_limit'])->units : 0,
                'terms_days' => $d['terms_days'] ?? 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('customer.created', 'customer', $id, $d);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    /** Sets a list price (per category or default) or a customer contract price. */
    public function setPrice(Request $request): JsonResponse
    {
        $d = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'price' => ['required', Decimal::RULE],
            'category' => ['nullable', 'in:wholesale,retail,direct', 'prohibits:customer_id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);
        $price = Decimal::of($d['price'])->units;
        DB::transaction(function () use ($d, $price) {
            if (isset($d['customer_id'])) {
                DB::table('contract_prices')->updateOrInsert(['customer_id' => $d['customer_id'], 'product_id' => $d['product_id']], ['price' => $price]);
            } else {
                DB::table('price_list_items')->updateOrInsert(['product_id' => $d['product_id'], 'category' => $d['category'] ?? null], ['price' => $price]);
            }
            $this->audit->record('price.set', 'product', $d['product_id'], $d);
        });

        return response()->json(null, 204);
    }
}
