<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

interface AccessRepository
{
    /** @return list<string> permission names granted through the user's roles */
    public function permissionsOf(int $userId): array;

    /** @return list<string> */
    public function rolesOf(int $userId): array;

    /** Highest approval level across the user's roles. */
    public function approvalLevelOf(int $userId): int;

    /** @return list<int> */
    public function warehousesOf(int $userId): array;

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $warehouseIds
     */
    public function replaceAccess(int $userId, array $roles, array $warehouseIds): void;

    /** @param list<string> $roles @return list<string> role names that do not exist */
    public function unknownRoles(array $roles): array;
}
