<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Superadmin
        User::create([
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'role' => 'superadmin',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'is_active' => true,
            'email_verified' => true,
        ]);

        // 2. Seed Custodian
        User::create([
            'email' => 'custodian@example.com',
            'password' => Hash::make('password123'),
            'role' => 'custodian',
            'first_name' => 'Custodian',
            'last_name' => 'User',
            'is_active' => true,
            'email_verified' => true,
        ]);

        // 3. Seed Instructor
        User::create([
            'email' => 'instructor@example.com',
            'password' => Hash::make('password123'),
            'role' => 'instructor',
            'first_name' => 'Instructor',
            'last_name' => 'User',
            'is_active' => true,
            'email_verified' => true,
        ]);

        // 4. Seed Student
        User::create([
            'email' => 'student@example.com',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'first_name' => 'Student',
            'last_name' => 'User',
            'is_active' => true,
            'email_verified' => true,
            'year_level' => '3rd Year',
            'block' => 'A',
            'agreed_to_terms' => true,
            'trust_score' => 100,
        ]);
    }
}
