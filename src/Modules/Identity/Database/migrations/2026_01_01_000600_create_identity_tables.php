<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Identity\Domain\Permission;
use Modules\Identity\Domain\RoleCatalog;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('label');
            $t->unsignedSmallInteger('approval_level')->default(0);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });

        Schema::create('user_warehouses', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $t->primary(['user_id', 'warehouse_id']);
        });

        $permissionIds = [];
        foreach (Permission::cases() as $permission) {
            $permissionIds[$permission->value] = DB::table('permissions')->insertGetId(['name' => $permission->value]);
        }
        foreach (RoleCatalog::defaults() as $name => $role) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $name, 'label' => $role['label'], 'approval_level' => $role['approval_level'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('permission_role')->insert(array_map(
                fn (Permission $p) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$p->value]],
                $role['permissions'],
            ));
        }
    }

    public function down(): void
    {
        foreach (['user_warehouses', 'role_user', 'permission_role', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('active'));
    }
};
