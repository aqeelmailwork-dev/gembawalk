<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => env('SUPER_ADMIN_EMAIL', 'superadmin@gembawalk.test')],
            [
                'name' => 'Super Admin',
                'password' => Hash::make(env('SUPER_ADMIN_PASSWORD', 'password')),
                'status' => UserStatus::Approved->value,
                'approved_at' => now(),
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([Role::SuperAdmin->value]);
    }
}
