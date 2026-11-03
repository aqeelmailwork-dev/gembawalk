<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Always start from a clean cache so permission checks see fresh data.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::firstOrCreate(['name' => $permission->value]);
        }

        $allPermissions = Permission::cases();

        $roles = [
            Role::SuperAdmin->value => $allPermissions,
            Role::Admin->value => [
                Permission::UsersApprove,
                Permission::UsersManage,
                Permission::WalksViewAll,
                Permission::ReportsExport,
            ],
            Role::User->value => [
                Permission::ReportsExport,
            ],
        ];

        foreach ($roles as $roleName => $permissions) {
            $role = RoleModel::firstOrCreate(['name' => $roleName]);

            $role->syncPermissions(array_map(
                fn (Permission $permission) => $permission->value,
                $permissions,
            ));
        }

        // Reset the cache again so the new matrix takes effect immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
