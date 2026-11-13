<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Permission;
use Modules\Identity\Domain\RoleCatalog;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Permission::cases() as $permission) {
            if (! DB::table('permissions')->where('name', $permission->value)->exists()) {
                DB::table('permissions')->insert(['name' => $permission->value]);
            }
        }
        foreach (RoleCatalog::defaults() as $name => $role) {
            $roleId = DB::table('roles')->where('name', $name)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($role['permissions'] as $permission) {
                $permissionId = DB::table('permissions')->where('name', $permission->value)->value('id');
                if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    public function down(): void {}
};
