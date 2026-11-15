<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

/** The administrator enters the company's details and each branch's own details on screen. */
final class CompanyAndBranchesApiTest extends ApiTestCase
{
    public function test_company_details_are_edited_on_screen_and_fall_back_to_settings(): void
    {
        $admin = $this->userWith(['admin']);
        $this->as($this->userWith(['finance_manager']))->putJson('/api/admin/company', ['name' => 'X'])->assertForbidden();

        $this->assertSame(config('erp.company_name'), $this->as($admin)->getJson('/api/admin/company')->assertOk()->json('name'));   // nothing entered yet: .env value
        $this->as($admin)->putJson('/api/admin/company', ['name' => '', 'tax_id' => 'T'])->assertUnprocessable();
        $this->as($admin)->putJson('/api/admin/company', ['name' => 'شركة جديدة', 'tax_id' => '300', 'address' => 'طرابلس', 'email' => 'bad'])->assertUnprocessable();
        $this->as($admin)->putJson('/api/admin/company', ['name' => 'شركة جديدة', 'tax_id' => '300', 'address' => 'طرابلس'])->assertOk()->assertJsonPath('tax_id', '300');

        $this->getJson('/api/public-info')->assertJsonPath('company_name', 'شركة جديدة');                 // login screen
        $this->as($admin)->getJson('/api/lookups')->assertJsonPath('company_name', 'شركة جديدة');
        $steps = collect($this->as($admin)->getJson('/api/admin/setup-status')->json('steps'))->pluck('done', 'key');
        $this->assertTrue($steps['company_details']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.updated']);
    }

    public function test_each_branch_has_its_own_details_and_cannot_close_with_stock(): void
    {
        $admin = $this->userWith(['admin']);
        $hr = $this->userWith(['hr_manager']);
        $manager = $this->as($hr)->postJson('/api/hr/employees', ['code' => 'M1', 'name' => 'مدير الفرع', 'hire_date' => '2024-01-01'])->assertCreated()->json('id');

        $id = $this->as($admin)->postJson('/api/warehouses', ['code' => 'BR2', 'name' => 'فرع مصراتة', 'type' => 'branch', 'city' => 'مصراتة', 'address' => 'الطريق الساحلي', 'phone' => '0911234567', 'manager_id' => $manager])->assertCreated()->json('id');
        $row = collect($this->as($admin)->getJson('/api/admin/branches')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame(['مصراتة', 'الطريق الساحلي', '0911234567', 'مدير الفرع', true], [$row['city'], $row['address'], $row['phone'], $row['manager'], $row['active']]);

        $this->as($admin)->putJson("/api/admin/branches/{$id}", ['phone' => '0920000000', 'commercial_register' => 'CR-77'])->assertNoContent();
        $this->assertSame(['0920000000', 'CR-77', 'فرع مصراتة'], [DB::table('warehouses')->where('id', $id)->value('phone'), DB::table('warehouses')->where('id', $id)->value('commercial_register'), DB::table('warehouses')->where('id', $id)->value('name')]);
        $this->as($this->userWith(['accountant']))->putJson("/api/admin/branches/{$id}", ['phone' => '1'])->assertForbidden();
        $this->as($admin)->putJson('/api/admin/branches/99999', ['phone' => '1'])->assertNotFound();

        // a branch holding goods cannot be closed; an empty one can, and comes back
        DB::table('stock_balances')->insert(['product_id' => $this->product, 'warehouse_id' => $id, 'on_hand' => 10000, 'reserved' => 0, 'value' => 0]);
        $this->as($admin)->putJson("/api/admin/branches/{$id}", ['active' => false])->assertUnprocessable();
        DB::table('stock_balances')->where('warehouse_id', $id)->update(['on_hand' => 0]);
        $this->as($admin)->putJson("/api/admin/branches/{$id}", ['active' => false])->assertNoContent();
        $this->assertNotContains($id, collect($this->as($admin)->getJson('/api/lookups')->json('warehouses'))->pluck('id')->all());
        $this->as($admin)->putJson("/api/admin/branches/{$id}", ['active' => true])->assertNoContent();
        $this->assertSame('الطريق الساحلي', collect($this->as($admin)->getJson('/api/lookups')->json('warehouses'))->firstWhere('id', $id)['address']);
    }
}
