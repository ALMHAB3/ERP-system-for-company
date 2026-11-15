<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

/** The administrator's "getting started" checklist. */
final class SetupStatusApiTest extends ApiTestCase
{
    public function test_checklist_follows_what_is_set_up(): void
    {
        $admin = $this->userWith(['admin']);
        $this->as($this->userWith(['accountant']))->getJson('/api/admin/setup-status')->assertForbidden();

        $steps = fn () => collect($this->as($admin)->getJson('/api/admin/setup-status')->assertOk()->json('steps'))->pluck('done', 'key');
        $s = $steps();
        $this->assertTrue($s['sample_data_removed']);
        $this->assertTrue($s['branches']);            // the test base creates a warehouse
        $this->assertFalse($s['exchange_rates']);
        $this->assertFalse($s['customers']);          // the built-in cash customer does not count
        $this->assertFalse($s['company_details']);

        DB::table('system_flags')->insert(['name' => 'demo_data', 'value' => '2026-01-01', 'created_at' => now()]);
        config(['erp.company.tax_id' => '123', 'erp.company.address' => 'Tripoli']);
        $this->as($this->userWith(['finance_manager']))->postJson('/api/currencies/rates', ['currency' => 'USD', 'rate' => '4.85'])->assertCreated();
        $s = $steps();
        $this->assertFalse($s['sample_data_removed']);
        $this->assertTrue($s['company_details']);
        $this->assertTrue($s['users']);
        $this->assertSame(['CNY', 'EUR'], $this->as($admin)->getJson('/api/admin/setup-status')->json('missing_rates'));
        $this->assertFalse($this->as($admin)->getJson('/api/admin/setup-status')->json('ready'));
    }
}
