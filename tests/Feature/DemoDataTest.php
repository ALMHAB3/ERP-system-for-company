<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Application\AuditVerifier;
use Modules\Finance\Domain\JournalRepository;
use Modules\Inventory\Application\LedgerReconciler;
use Modules\Shared\Domain\Fixed;
use Tests\TestCase;

/** The sample company history is produced by the real services, so it doubles as a whole-system consistency test. */
final class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_history_is_consistent_everywhere(): void
    {
        $this->artisan('erp:demo-data', ['--months' => 4, '--with-users' => true])->assertSuccessful();

        $debits = $credits = 0;
        foreach (app(JournalRepository::class)->trialBalance() as $r) {
            $debits += Fixed::parse($r['debit'])->units;
            $credits += Fixed::parse($r['credit'])->units;
        }
        $this->assertSame($debits, $credits, 'the demo books balance');
        $this->assertSame([], app(LedgerReconciler::class)->reconcile(), 'stock balances equal the ledger');
        $this->assertTrue(app(AuditVerifier::class)->verify()['ok'], 'audit chain intact');

        $this->assertGreaterThan(20, DB::table('sales_orders')->count());
        $methods = DB::table('sales_orders')->distinct()->pluck('payment_method')->sort()->values()->all();
        $this->assertSame(['cash', 'credit', 'transfer'], $methods);
        $this->assertGreaterThanOrEqual(1, DB::table('shipments')->where('status', 'received')->count());
        $this->assertSame(2, DB::table('bank_accounts')->count());
        $this->assertTrue(DB::table('system_flags')->where('name', 'demo_data')->exists());
        $this->assertSame(6, DB::table('users')->count());
        $this->assertSame(1, DB::table('customers')->where('is_walk_in', true)->count());

        // today's date is back after the time travel
        $this->assertSame(now()->toDateString(), (new \DateTimeImmutable)->format('Y-m-d'));
    }

    public function test_it_refuses_to_run_on_a_system_that_already_has_data(): void
    {
        DB::table('products')->insert(['sku' => 'X', 'name' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('erp:demo-data')->assertFailed();
        $this->assertSame(0, DB::table('suppliers')->count());
    }

    public function test_the_company_name_is_public_and_arabic(): void
    {
        $this->getJson('/api/public-info')->assertOk()->assertJsonPath('company_name', 'شركة هب الريح لعوازل الأسطح ومواد البناء');
    }
}
