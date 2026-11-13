<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Modules\Identity\Domain\AccessDenied;
use Modules\Identity\Domain\AccessRepository;
use Modules\Identity\Domain\Permission;

/** RBAC + warehouse data scope. Request-scoped (registered as scoped), so lookups are memoised per request. */
final class AccessControl
{
    /** @var array<int, list<string>> */
    private array $permissions = [];

    /** @var array<int, list<int>> */
    private array $warehouses = [];

    public function __construct(private readonly AccessRepository $access) {}

    public function can(int $userId, Permission $permission): bool
    {
        $this->permissions[$userId] ??= $this->access->permissionsOf($userId);

        return in_array($permission->value, $this->permissions[$userId], true);
    }

    public function authorize(int $userId, Permission $permission): void
    {
        if (! $this->can($userId, $permission)) {
            throw new AccessDenied("Missing permission: {$permission->value}.");
        }
    }

    public function canUseWarehouse(int $userId, int $warehouseId): bool
    {
        if ($this->can($userId, Permission::WarehousesAll)) {
            return true;
        }
        $this->warehouses[$userId] ??= $this->access->warehousesOf($userId);

        return in_array($warehouseId, $this->warehouses[$userId], true);
    }

    public function authorizeWarehouse(int $userId, int ...$warehouseIds): void
    {
        foreach ($warehouseIds as $warehouseId) {
            if (! $this->canUseWarehouse($userId, $warehouseId)) {
                throw new AccessDenied("No access to warehouse {$warehouseId}.");
            }
        }
    }

    public function approvalLevel(int $userId): int
    {
        return $this->access->approvalLevelOf($userId);
    }
}
