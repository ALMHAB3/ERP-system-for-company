<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

final class ListsApiTest extends ApiTestCase
{
    public function test_lookups_only_offer_the_warehouses_the_user_can_use(): void
    {
        $other = $this->makeWarehouse('BR2');
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $finance = $this->userWith(['finance_manager']);

        $codes = fn ($u) => array_column($this->as($u)->getJson('/api/lookups')->assertOk()->json('warehouses'), 'code');
        $this->assertSame(['MAIN'], $codes($clerk));
        $this->assertEqualsCanonicalizing(['MAIN', 'BR2'], $codes($finance));
        $this->assertNotSame($other, $this->warehouse);
    }

    public function test_lists_follow_permissions_and_warehouse_scope(): void
    {
        $other = $this->makeWarehouse('BR2');
        $customer = DB::table('customers')->insertGetId(['name' => 'C', 'category' => 'retail', 'credit_limit' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$this->warehouse, $other] as $w) {
            DB::table('sales_orders')->insert(['number' => "SO-{$w}", 'customer_id' => $customer, 'warehouse_id' => $w, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        }
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $admin = $this->userWith(['admin']);

        $this->as($rep)->getJson('/api/sales-orders')->assertOk()->assertJsonCount(1, 'data');
        $this->as($admin)->getJson('/api/sales-orders')->assertOk()->assertJsonCount(2, 'data');
        $this->as($clerk)->getJson('/api/shipments')->assertOk();                  // receives shipments
        $this->as($rep)->getJson('/api/shipments')->assertForbidden();
        $this->as($rep)->getJson('/api/admin/users')->assertForbidden();
        $this->as($admin)->getJson('/api/admin/users')->assertOk()->assertJsonStructure(['data' => [['id', 'email', 'roles', 'warehouses']]]);
    }

    public function test_master_data_updates_are_audited(): void
    {
        $buyer = $this->userWith(['purchasing_manager']);
        $this->as($buyer)->putJson("/api/products/{$this->product}", ['lead_time_days' => 70, 'name' => 'Renamed'])->assertNoContent();
        $this->assertSame(70, (int) DB::table('products')->where('id', $this->product)->value('lead_time_days'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'product.updated')->exists());
        $this->as($buyer)->putJson("/api/products/{$this->product}", ['lead_time_days' => 9999])->assertUnprocessable();
    }
}
