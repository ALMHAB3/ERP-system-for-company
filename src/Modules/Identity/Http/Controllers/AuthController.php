<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Application\AuditLogger;
use Modules\Identity\Domain\AccessRepository;

final class AuthController
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessRepository $access) {}

    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        // Same answer and same hashing cost whether or not the e-mail exists: no account enumeration.
        $valid = $user !== null ? Hash::check($data['password'], $user->password) : (bool) Hash::make($data['password']) && false;
        if (! $user || ! $valid || ! $user->active) {
            $this->audit->record('auth.login_failed', 'user', $user?->id, ['email' => $data['email']], userId: $user?->id);
            throw ValidationException::withMessages(['email' => ['These credentials are not valid.']]);
        }

        $ttl = config('sanctum.expiration');
        $token = $user->createToken($data['device_name'], ['*'], $ttl ? now()->addMinutes((int) $ttl) : null);
        $this->audit->record('auth.login', 'user', $user->id, ['device' => $data['device_name']], userId: $user->id);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ], 201);
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $id = (int) $request->user()->id;

        return response()->json([
            'id' => $id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'roles' => $this->access->rolesOf($id),
            'permissions' => $this->access->permissionsOf($id),
            'warehouses' => $this->access->warehousesOf($id),
            'approval_level' => $this->access->approvalLevelOf($id),
        ]);
    }

    /**
     * The signed-in user changes their own name, login e-mail and/or password. Always requires the current password.
     * A new password signs out every other session of the account (this one stays signed in).
     */
    public function updateAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            $this->audit->record('user.account_change_refused', 'user', $user->id, [], userId: $user->id);
            throw ValidationException::withMessages(['current_password' => ['The current password is not correct.']]);
        }
        $changes = array_filter(array_intersect_key($data, ['name' => 1, 'email' => 1, 'password' => 1]), static fn ($v) => $v !== null && $v !== '');
        if ($changes === []) {
            throw ValidationException::withMessages(['name' => ['Nothing to change.']]);
        }

        DB::transaction(function () use ($user, $changes) {
            $user->forceFill($changes)->save();
            if (isset($changes['password'])) {
                $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
            }
            // Never log the password itself, only that it changed.
            $this->audit->record('user.account_updated', 'user', $user->id, [
                'name' => $changes['name'] ?? null, 'email' => $changes['email'] ?? null, 'password_changed' => isset($changes['password']),
            ], userId: $user->id);
        });

        return response()->json(['name' => $user->name, 'email' => $user->email]);
    }
}
