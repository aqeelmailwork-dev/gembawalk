<?php

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Existing installs were seeded before checklists.manage existed, and seeders are
 * not re-run on deploy, so grant it here to the roles that get it by default.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roles = RoleModel::whereIn('name', [Role::SuperAdmin->value, Role::Admin->value])->get();

        // Fresh databases have no roles yet; the seeder will set everything up.
        if ($roles->isEmpty()) {
            return;
        }

        PermissionModel::firstOrCreate(['name' => Permission::ChecklistsManage->value]);

        foreach ($roles as $role) {
            $role->givePermissionTo(Permission::ChecklistsManage->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        PermissionModel::where('name', Permission::ChecklistsManage->value)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
