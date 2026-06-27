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
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'password' => Hash::make('password123'),
                'role' => 'superadmin',
                'first_name' => 'Admin',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'superadmin@gmail.com'],
            [
                'password' => Hash::make('Password@123'),
                'role' => 'superadmin',
                'first_name' => 'Superadmin',
                'last_name' => 'GMail',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 2. Seed Custodian
        User::firstOrCreate(
            ['email' => 'custodian@example.com'],
            [
                'password' => Hash::make('password123'),
                'role' => 'custodian',
                'first_name' => 'Custodian',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'custodian@gmail.com'],
            [
                'password' => Hash::make('Password@123'),
                'role' => 'custodian',
                'first_name' => 'Custodian',
                'last_name' => 'GMail',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 3. Seed Instructor
        User::firstOrCreate(
            ['email' => 'instructor@example.com'],
            [
                'password' => Hash::make('password123'),
                'role' => 'instructor',
                'first_name' => 'Instructor',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 4. Seed Student
        User::firstOrCreate(
            ['email' => 'student@example.com'],
            [
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
            ]
        );

        // 5. Seed Admin
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'first_name' => 'System',
                'last_name' => 'Admin',
                'is_active' => true,
                'email_verified' => true,
            ]
        );
    }
}
