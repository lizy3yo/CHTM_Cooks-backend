<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['student', 'custodian', 'instructor', 'superadmin']);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('profile_photo_url')->nullable();
            $table->string('profile_photo_public_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->string('email_verification_token')->nullable();
            $table->timestamp('email_verification_expires')->nullable();
            $table->string('password_reset_token')->nullable();
            $table->timestamp('password_reset_expires')->nullable();
            $table->string('year_level')->nullable();
            $table->string('block')->nullable();
            $table->boolean('agreed_to_terms')->default(false);
            $table->integer('trust_score')->default(100);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
