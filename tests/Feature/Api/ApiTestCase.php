<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Modules\Identity\Domain\AccessRepository;
use Tests\Feature\ErpTestCase;

abstract class ApiTestCase extends ErpTestCase
{
    /** @param list<string> $roles @param list<int> $warehouses */
    protected function userWith(array $roles, array $warehouses = [], string $password = 'Str0ng-Passw0rd!'): User
    {
        $user = User::query()->create([
            'name' => implode('+', $roles), 'email' => uniqid('u', true).'@example.test', 'password' => $password, 'active' => true,
        ]);
        app(AccessRepository::class)->replaceAccess($user->id, $roles, $warehouses);

        return $user;
    }

    /** Authenticates subsequent requests with a real Sanctum bearer token. */
    protected function as(User $user): static
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
