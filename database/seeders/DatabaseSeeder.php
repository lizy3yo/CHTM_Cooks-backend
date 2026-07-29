<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Superadmin
        User::updateOrCreate(
            ['email' => 'superadmin@gmail.com'],
            [
                'password' => Hash::make('Password@123'),
                'role' => 'superadmin',
                'first_name' => 'Superadmin',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 2. Admin
        User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'password' => Hash::make('Password@123'),
                'role' => 'admin',
                'first_name' => 'Admin',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 3. Custodian
        User::updateOrCreate(
            ['email' => 'custodian@gmail.com'],
            [
                'password' => Hash::make('Password@123'),
                'role' => 'custodian',
                'first_name' => 'Custodian',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 4. Instructor
        User::updateOrCreate(
            ['email' => 'instructor@gmail.com'],
            [
                'password' => Hash::make('Password@123'),
                'role' => 'instructor',
                'first_name' => 'Instructor',
                'last_name' => 'User',
                'is_active' => true,
                'email_verified' => true,
            ]
        );

        // 5. Student
        User::updateOrCreate(
            ['email' => '202311564@gordoncollege.edu.ph'],
            [
                'password' => Hash::make('Password@123'),
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
    }
}
