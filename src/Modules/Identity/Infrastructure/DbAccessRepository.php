<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use Modules\Identity\Domain\AccessRepository;

final class DbAccessRepository implements AccessRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function permissionsOf(int $userId): array
    {
        return $this->db->table('role_user as ru')
            ->join('permission_role as pr', 'pr.role_id', '=', 'ru.role_id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->join('users as u', 'u.id', '=', 'ru.user_id')
            ->where('ru.user_id', $userId)->where('u.active', true)
            ->distinct()->pluck('p.name')->all();
    }

    public function rolesOf(int $userId): array
    {
        return $this->db->table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('ru.user_id', $userId)->orderBy('r.name')->pluck('r.name')->all();
    }

    public function approvalLevelOf(int $userId): int
    {
        return (int) $this->db->table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->where('ru.user_id', $userId)->max('r.approval_level');
    }

    public function warehousesOf(int $userId): array
    {
        return $this->db->table('user_warehouses')->where('user_id', $userId)
            ->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
    }

    public function replaceAccess(int $userId, array $roles, array $warehouseIds): void
    {
        $roleIds = $this->db->table('roles')->whereIn('name', $roles)->pluck('id');
        $this->db->table('role_user')->where('user_id', $userId)->delete();
        $this->db->table('role_user')->insert($roleIds->map(fn ($id) => ['user_id' => $userId, 'role_id' => $id])->all());
        $this->db->table('user_warehouses')->where('user_id', $userId)->delete();
        $this->db->table('user_warehouses')->insert(array_map(fn ($w) => ['user_id' => $userId, 'warehouse_id' => $w], array_values(array_unique($warehouseIds))));
    }

    public function unknownRoles(array $roles): array
    {
        return array_values(array_diff($roles, $this->db->table('roles')->whereIn('name', $roles)->pluck('name')->all()));
    }
}
