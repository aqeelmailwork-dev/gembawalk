<?php

namespace App\Enums;

enum Permission: string
{
    case UsersApprove = 'users.approve';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case WalksViewAll = 'walks.view_all';
    case WalksDeleteAny = 'walks.delete_any';
    case ReportsExport = 'reports.export';
    case ChecklistsManage = 'checklists.manage';

    /**
     * Human-readable label for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::UsersApprove => 'Approve users',
            self::UsersManage => 'Manage users',
            self::RolesManage => 'Manage roles',
            self::WalksViewAll => 'View all walks',
            self::WalksDeleteAny => 'Delete any walk',
            self::ReportsExport => 'Export reports',
            self::ChecklistsManage => 'Manage checklists',
        };
    }

    /**
     * Description of what the permission allows.
     */
    public function description(): string
    {
        return match ($this) {
            self::UsersApprove => 'Approve or reject sign-ups',
            self::UsersManage => 'View user list, deactivate or reactivate',
            self::RolesManage => 'Change a user\'s role',
            self::WalksViewAll => 'See every user\'s walks',
            self::WalksDeleteAny => 'Delete anyone\'s walk',
            self::ReportsExport => 'Download PDF reports',
            self::ChecklistsManage => 'Create and edit checklist categories and their yes/no checks',
        };
    }
}
