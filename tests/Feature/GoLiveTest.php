<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\AccessRepository;
use Tests\TestCase;

/** From trial to real work: sample data goes, the administrator stays, the reference data is back. */
final class GoLiveTest extends TestCase
{
    // The command rebuilds the whole database itself, so no wrapping transaction: each test starts from a fresh schema.
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function admin(string $email, string $password): User
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => $email, 'password' => $password, 'active' => true]);
        app(AccessRepository::class)->replaceAccess($user->id, ['admin'], []);

        return $user;
    }

    public function test_sample_data_is_erased_and_the_administrator_keeps_the_same_login(): void
    {
        $this->admin('owner@company.ly', 'Real-Admin-Passw0rd');
        $this->artisan('erp:demo-data', ['--months' => 3, '--with-users' => true])->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('products')->count());
        $this->assertGreaterThan(0, DB::table('journal_lines')->count());

        $this->artisan('erp:go-live')->expectsQuestion('Type RESET to continue', 'no')->assertFailed();
        $this->assertGreaterThan(0, DB::table('products')->count());                         // cancelled: untouched

        $this->artisan('erp:go-live')->expectsQuestion('Type RESET to continue', 'RESET')->assertSuccessful();

        foreach (['products', 'stock_moves', 'warehouses', 'suppliers', 'sales_orders', 'journal_entries', 'journal_lines', 'employees', 'payroll_runs', 'employment_contracts', 'bank_accounts', 'exchange_rates', 'system_flags'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
        $this->assertSame(['owner@company.ly'], DB::table('users')->pluck('email')->all());   // demo users gone
        $owner = User::query()->where('email', 'owner@company.ly')->first();
        $this->assertTrue(Hash::check('Real-Admin-Passw0rd', $owner->password));
        $this->assertSame(['admin'], app(AccessRepository::class)->rolesOf($owner->id));
        // reference data every installation needs is back
        $this->assertGreaterThan(0, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('customers')->where('is_walk_in', true)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.went_live']);

        $this->postJson('/api/auth/token', ['email' => 'owner@company.ly', 'password' => 'Real-Admin-Passw0rd', 'device_name' => 't'])->assertCreated();
        $this->assertFalse($this->withToken($owner->createToken('t')->plainTextToken)->getJson('/api/lookups')->assertOk()->json('demo'));
    }

    public function test_a_system_with_real_data_is_protected(): void
    {
        $this->admin('owner@company.ly', 'Real-Admin-Passw0rd');
        DB::table('warehouses')->insert(['code' => 'MAIN', 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('erp:go-live', ['--confirm' => 'RESET'])->assertFailed();
        $this->assertSame(1, DB::table('warehouses')->count());

        $this->artisan('erp:go-live', ['--confirm' => 'RESET', '--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('warehouses')->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_without_an_administrator_one_is_created_at_the_end(): void
    {
        $this->artisan('erp:demo-data', ['--months' => 3, '--with-users' => true])->assertSuccessful();
        $this->artisan('erp:go-live', ['--confirm' => 'RESET'])
            ->expectsQuestion('Administrator name', 'Owner')
            ->expectsQuestion('Administrator e-mail (login)', 'owner@company.ly')
            ->expectsQuestion('Password (min 12 chars, mixed case, numbers)', 'Real-Admin-Passw0rd')
            ->expectsQuestion('Repeat the password', 'Real-Admin-Passw0rd')
            ->assertSuccessful();
        $this->assertSame(['owner@company.ly'], DB::table('users')->pluck('email')->all());
        $this->assertSame(0, DB::table('products')->count());
    }
}
