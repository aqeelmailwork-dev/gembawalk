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
        $user = User::firstOrNew([
            'email' => env('SUPER_ADMIN_EMAIL', 'superadmin@gembawalk.test'),
        ]);

        // The password is only set when the user is first created, so re-seeding
        // never resets a password that has been changed since.
        if (! $user->exists) {
            $user->password = Hash::make(env('SUPER_ADMIN_PASSWORD', 'password'));
            $user->email_verified_at = now();
            $user->approved_at = now();
        }

        $user->name = 'Super Admin';
        $user->status = UserStatus::Approved->value;

        $user->save();

        $user->syncRoles([Role::SuperAdmin->value]);
    }
}
