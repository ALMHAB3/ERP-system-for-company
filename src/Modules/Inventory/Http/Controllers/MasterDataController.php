<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditLogger;

final class MasterDataController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function storeProduct(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:200'],
            'base_uom' => ['string', 'max:16'],
            'lead_time_days' => ['integer', 'between:0,365'],
            'cover_days' => ['integer', 'between:1,365'],
            'tracks_serials' => ['boolean'],
            'has_expiry' => ['boolean'],
        ]);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('products')->insertGetId($d + ['base_uom' => 'PCS', 'lead_time_days' => 45, 'cover_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('product.created', 'product', $id, $d);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:main,branch,transit,returns,damaged'],
        ]);
        $id = DB::transaction(function () use ($d) {
            $id = DB::table('warehouses')->insertGetId($d + ['created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('warehouse.created', 'warehouse', $id, $d);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }
}
