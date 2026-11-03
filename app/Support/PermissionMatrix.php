<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

class PermissionMatrix
{
    /**
     * The default permission matrix for the configurable roles.
     *
     * Super Admin is intentionally absent: it always receives every permission.
     *
     * @return array<string, array<int, Permission>>
     */
    public static function defaults(): array
    {
        return [
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
    }

    /**
     * The roles whose permissions can be edited on the access-control page.
     *
     * @return array<int, Role>
     */
    public static function configurableRoles(): array
    {
        return [Role::Admin, Role::User];
    }

    /**
     * Reset the stored permission matrix back to its defaults.
     */
    public static function reset(): void
    {
        foreach (self::configurableRoles() as $role) {
            $roleModel = RoleModel::firstOrCreate(['name' => $role->value]);

            $roleModel->syncPermissions(array_map(
                fn (Permission $permission) => $permission->value,
                self::defaults()[$role->value],
            ));
        }

        $superAdmin = RoleModel::firstOrCreate(['name' => Role::SuperAdmin->value]);
        $superAdmin->syncPermissions(array_map(
            fn (Permission $permission) => $permission->value,
            Permission::cases(),
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
