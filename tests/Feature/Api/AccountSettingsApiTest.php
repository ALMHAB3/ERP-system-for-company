<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/** Every user, the administrator included, can change their own name, login e-mail and password. */
final class AccountSettingsApiTest extends ApiTestCase
{
    private const PASSWORD = 'Str0ng-Passw0rd!';

    private function login(string $email, string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])->postJson('/api/auth/token', ['email' => $email, 'password' => $password, 'device_name' => 'test']);
    }

    public function test_admin_changes_login_email_name_and_password(): void
    {
        $admin = $this->userWith(['admin']);
        $old = $admin->email;
        $other = $admin->createToken('other device')->plainTextToken;
        $this->as($admin);

        $this->putJson('/api/me/account', ['current_password' => 'wrong', 'password' => 'N3w-Passw0rd-XYZ', 'password_confirmation' => 'N3w-Passw0rd-XYZ'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/me/account', ['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/me/account', ['current_password' => self::PASSWORD, 'password' => 'N3w-Passw0rd-XYZ', 'password_confirmation' => 'different'])->assertUnprocessable();
        $this->putJson('/api/me/account', ['current_password' => self::PASSWORD, 'email' => $this->userWith(['accountant'])->email])->assertUnprocessable()->assertJsonValidationErrors('email');   // taken
        $this->putJson('/api/me/account', ['current_password' => self::PASSWORD])->assertUnprocessable();                                                                  // nothing to change

        $this->putJson('/api/me/account', [
            'current_password' => self::PASSWORD, 'name' => 'Boss', 'email' => 'boss@company.test', 'password' => 'N3w-Passw0rd-XYZ', 'password_confirmation' => 'N3w-Passw0rd-XYZ',
        ])->assertOk()->assertJsonPath('email', 'boss@company.test')->assertJsonPath('name', 'Boss');

        $fresh = User::query()->find($admin->id);
        $this->assertTrue(Hash::check('N3w-Passw0rd-XYZ', $fresh->password));
        $this->assertSame(1, $fresh->tokens()->count());                                   // the other device was signed out, this one stays
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', 'boss@company.test');
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/me')->assertUnauthorized();

        $this->login($old, self::PASSWORD)->assertUnprocessable();
        $this->login('boss@company.test', self::PASSWORD)->assertUnprocessable();
        $this->login('boss@company.test', 'N3w-Passw0rd-XYZ')->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.account_updated']);
        $this->assertStringNotContainsString('N3w-Passw0rd-XYZ', (string) DB::table('audit_logs')->pluck('data')->implode(' '));
    }

    public function test_name_only_change_keeps_the_password_and_sessions(): void
    {
        $user = $this->userWith(['accountant']);
        $user->createToken('other');
        $this->as($user)->putJson('/api/me/account', ['current_password' => self::PASSWORD, 'name' => 'New name'])->assertOk();
        $this->assertSame(2, User::query()->find($user->id)->tokens()->count());
        $this->assertTrue(Hash::check(self::PASSWORD, User::query()->find($user->id)->password));
    }

    public function test_console_recovery_sets_new_credentials(): void
    {
        $admin = $this->userWith(['admin']);
        $admin->createToken('x');
        $this->artisan('erp:set-credentials', ['email' => 'nobody@x.test'])->assertFailed();
        $this->artisan('erp:set-credentials', ['email' => $admin->email, '--new-email' => 'owner@company.test', '--name' => 'Owner'])
            ->expectsQuestion('New password (min 12 chars, mixed case, numbers)', 'Recover3d-Passw0rd')
            ->expectsQuestion('Repeat the new password', 'Recover3d-Passw0rd')
            ->assertSuccessful();
        $fresh = User::query()->find($admin->id);
        $this->assertSame(['owner@company.test', 'Owner'], [$fresh->email, $fresh->name]);
        $this->assertTrue(Hash::check('Recover3d-Passw0rd', $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());
        $this->artisan('erp:set-credentials', ['email' => 'owner@company.test'])
            ->expectsQuestion('New password (min 12 chars, mixed case, numbers)', 'weak')->expectsQuestion('Repeat the new password', 'weak')->assertFailed();
        $this->artisan('erp:set-credentials', ['email' => 'owner@company.test', '--keep-password' => true, '--name' => 'Owner 2'])->assertSuccessful();
        $this->assertTrue(Hash::check('Recover3d-Passw0rd', User::query()->find($admin->id)->password));
    }
}
