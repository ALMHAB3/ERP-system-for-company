<?php

declare(strict_types=1);

namespace Modules\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
}
