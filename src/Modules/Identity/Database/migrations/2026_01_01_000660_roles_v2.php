<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Permission;
use Modules\Identity\Domain\RoleCatalog;

/** Arabic role names, an accountant role, and managers no longer make sales (only the sales representative does). */
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
                $roleId = DB::table('roles')->insertGetId(['name' => $name, 'label' => $role['label'], 'approval_level' => $role['approval_level'], 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('roles')->where('id', $roleId)->update(['label' => $role['label']]);
            }
            foreach ($role['permissions'] as $permission) {
                $pid = DB::table('permissions')->where('name', $permission->value)->value('id');
                if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $pid]);
                }
            }
        }
        $salesCreate = DB::table('permissions')->where('name', Permission::SalesCreate->value)->value('id');
        $managers = DB::table('roles')->whereIn('name', ['admin', 'general_manager'])->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $managers)->where('permission_id', $salesCreate)->delete();
    }

    public function down(): void {}
};
