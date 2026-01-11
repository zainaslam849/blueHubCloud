<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'admin@bluehubcloud.com';
    public const DEFAULT_PASSWORD = '++Team@@11@@';

    /**
     * Seed an admin user for local/dev login.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::DEFAULT_EMAIL],
            [
                'name' => 'Admin',
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make(self::DEFAULT_PASSWORD),
            ]
        );
    }
}
