<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

final class AuthAndAccessTest extends ApiTestCase
{
    public function test_login_issues_an_expiring_token_and_is_audited(): void
    {
        $user = $this->userWith(['sales_rep'], [$this->warehouse]);

        $res = $this->postJson('/api/auth/token', ['email' => $user->email, 'password' => 'Str0ng-Passw0rd!', 'device_name' => 'phone'])
            ->assertCreated()->assertJsonStructure(['token', 'token_type', 'expires_at']);
        $this->assertNotNull($res->json('expires_at'));

        $this->withToken($res->json('token'))->getJson('/api/me')
            ->assertOk()->assertJsonPath('roles', ['sales_rep'])->assertJsonPath('warehouses', [$this->warehouse]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'auth.login')->where('user_id', $user->id)->exists());
    }

    public function test_wrong_password_unknown_email_and_inactive_user_get_the_same_answer(): void
    {
        $user = $this->userWith(['sales_rep']);
        $inactive = $this->userWith(['sales_rep']);
        $inactive->forceFill(['active' => false])->save();

        foreach ([
            ['email' => $user->email, 'password' => 'wrong'],
            ['email' => 'nobody@example.test', 'password' => 'whatever'],
            ['email' => $inactive->email, 'password' => 'Str0ng-Passw0rd!'],
        ] as $attempt) {
            $this->postJson('/api/auth/token', $attempt + ['device_name' => 'x'])
                ->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials are not valid.');
        }
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'auth.login_failed')->count());
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->userWith(['sales_rep']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/token', ['email' => $user->email, 'password' => 'bad', 'device_name' => 'x'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/token', ['email' => $user->email, 'password' => 'Str0ng-Passw0rd!', 'device_name' => 'x'])->assertStatus(429);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson('/api/inventory/balances')->assertUnauthorized();
    }

    public function test_a_role_without_the_permission_gets_403_and_the_attempt_is_audited(): void
    {
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);

        $this->as($rep)->postJson('/api/inventory/adjustments', [
            'product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'direction' => 'in', 'quantity' => '5', 'unit_cost' => '1', 'reason' => 'x',
        ])->assertForbidden()->assertJsonPath('message', 'Missing permission: inventory.adjust.');

        $log = DB::table('audit_logs')->where('action', 'http.post')->latest('id')->first();
        $this->assertSame($rep->id, (int) $log->user_id);
        $this->assertSame(403, json_decode($log->data, true)['status']);
    }

    public function test_warehouse_scope_blocks_other_warehouses(): void
    {
        $other = $this->makeWarehouse('BR2');
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $payload = fn (int $w) => ['product_id' => $this->product, 'warehouse_id' => $w, 'direction' => 'in', 'quantity' => '5', 'unit_cost' => '2', 'reason' => 'count'];

        $this->as($clerk)->postJson('/api/inventory/adjustments', $payload($this->warehouse))->assertCreated();
        $this->as($clerk)->postJson('/api/inventory/adjustments', $payload($other))
            ->assertForbidden()->assertJsonPath('message', "No access to warehouse {$other}.");
    }

    public function test_cost_is_hidden_without_the_view_cost_permission(): void
    {
        $clerk = $this->userWith(['warehouse_clerk'], [$this->warehouse]);
        $this->as($clerk)->postJson('/api/inventory/adjustments', [
            'product_id' => $this->product, 'warehouse_id' => $this->warehouse, 'direction' => 'in', 'quantity' => '10', 'unit_cost' => '3', 'reason' => 'opening',
        ])->assertCreated();

        $row = $this->as($clerk)->getJson('/api/inventory/balances')->assertOk()->json('data.0');
        $this->assertSame('10.0000', $row['on_hand']);
        $this->assertArrayNotHasKey('value', $row);

        $finance = $this->userWith(['finance_manager']);
        $this->as($finance)->getJson('/api/inventory/balances')->assertOk()->assertJsonPath('data.0.average_cost', '3.0000');
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $admin = $this->userWith(['admin']);
        $rep = $this->userWith(['sales_rep'], [$this->warehouse]);
        $token = $rep->createToken('t')->plainTextToken;

        $this->as($admin)->putJson("/api/admin/users/{$rep->id}/access", ['roles' => ['sales_rep'], 'active' => false])->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_admin_creates_users_with_strong_passwords_and_known_roles_only(): void
    {
        $admin = $this->userWith(['admin']);
        $this->as($admin)->postJson('/api/admin/users', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'short', 'roles' => ['sales_rep']])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->as($admin)->postJson('/api/admin/users', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'Very-Str0ng-Pass', 'roles' => ['emperor']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->as($admin)->postJson('/api/admin/users', ['name' => 'A', 'email' => 'a@example.test', 'password' => 'Very-Str0ng-Pass', 'roles' => ['sales_rep'], 'warehouses' => [$this->warehouse]])
            ->assertCreated();

        $log = DB::table('audit_logs')->where('action', 'http.post')->latest('id')->first();
        $this->assertStringNotContainsString('Very-Str0ng-Pass', $log->data, 'passwords must never reach the audit log');
    }

    public function test_console_creates_the_first_admin(): void
    {
        $this->artisan('erp:create-admin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Password (min 12 chars, mixed case, numbers)', 'Very-Str0ng-Pass')
            ->assertSuccessful();
        $this->postJson('/api/auth/token', ['email' => 'boss@example.test', 'password' => 'Very-Str0ng-Pass', 'device_name' => 'cli'])->assertCreated();
    }
}
