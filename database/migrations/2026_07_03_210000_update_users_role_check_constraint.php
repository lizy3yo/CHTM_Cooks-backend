<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Drop the old check constraint if it exists and add the updated list including 'admin'
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text IN ('student', 'custodian', 'instructor', 'superadmin', 'admin'))");
        } else {
            // For non-PostgreSQL databases (MySQL, SQLite), modify the column via Schema builder
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['student', 'custodian', 'instructor', 'superadmin', 'admin'])->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text IN ('student', 'custodian', 'instructor', 'superadmin'))");
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['student', 'custodian', 'instructor', 'superadmin'])->change();
            });
        }
    }
};
