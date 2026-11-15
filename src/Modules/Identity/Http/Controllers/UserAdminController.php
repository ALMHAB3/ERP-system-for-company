<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Application\AuditLogger;
use Modules\Identity\Domain\AccessRepository;
use Modules\Identity\Domain\Permission;

final class UserAdminController
{
    public function __construct(private readonly AccessRepository $access, private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
            'warehouses' => ['array'],
            'warehouses.*' => ['integer', 'exists:warehouses,id'],
        ]);
        $this->assertRolesExist($data['roles']);
        $this->assertBranchGiven($data['roles'], $data['warehouses'] ?? []);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'active' => true]);
            $this->access->replaceAccess($user->id, $data['roles'], $data['warehouses'] ?? []);
            $this->audit->record('user.created', 'user', $user->id, ['roles' => $data['roles'], 'warehouses' => $data['warehouses'] ?? []]);

            return $user;
        });

        return response()->json(['id' => $user->id], 201);
    }

    public function updateAccess(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string'],
            'warehouses' => ['array'],
            'warehouses.*' => ['integer', 'exists:warehouses,id'],
            'active' => ['boolean'],
        ]);
        $this->assertRolesExist($data['roles']);
        if (($data['active'] ?? true) !== false) {
            $this->assertBranchGiven($data['roles'], $data['warehouses'] ?? []);
        }
        $target = User::query()->findOrFail($user);

        DB::transaction(function () use ($target, $data) {
            $before = ['roles' => $this->access->rolesOf($target->id), 'warehouses' => $this->access->warehousesOf($target->id), 'active' => $target->active];
            $this->access->replaceAccess($target->id, $data['roles'], $data['warehouses'] ?? []);
            if (array_key_exists('active', $data)) {
                $target->forceFill(['active' => $data['active']])->save();
                if (! $data['active']) {
                    $target->tokens()->delete(); // deactivation logs the user out everywhere
                }
            }
            $this->audit->record('user.access_changed', 'user', $target->id, ['before' => $before, 'after' => $data]);
        });

        return response()->json(null, 204);
    }

    /**
     * Branch roles (and sellers, storekeepers) only ever see their own branches: without one they would see nothing.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $warehouses
     */
    private function assertBranchGiven(array $roles, array $warehouses): void
    {
        $companyWide = DB::table('roles as r')->join('permission_role as pr', 'pr.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->whereIn('r.name', $roles)->where('p.name', Permission::WarehousesAll->value)->exists();
        if (! $companyWide && $warehouses === []) {
            throw ValidationException::withMessages(['warehouses' => ['Choose the branch this user works in.']]);
        }
    }

    /** @param list<string> $roles */
    private function assertRolesExist(array $roles): void
    {
        if ($unknown = $this->access->unknownRoles($roles)) {
            throw ValidationException::withMessages(['roles' => ['Unknown role(s): '.implode(', ', $unknown)]]);
        }
    }
}
