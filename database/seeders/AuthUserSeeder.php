<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AuthUserSeeder extends Seeder
{
    /**
     * Seed authentication users.
     */
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'is_active' => true,
                'preferred_language' => 'en',
            ]
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }
    }
}
