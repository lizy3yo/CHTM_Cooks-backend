<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('audience_role', ['student', 'instructor', 'custodian', 'superadmin', 'supervisor']);
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('link')->nullable();
            $table->foreignId('borrow_request_id')->nullable()->constrained('borrow_requests')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('token_hash');
            $table->string('selector');
            $table->string('device_fingerprint')->nullable();
            $table->string('device_name')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_revoked')->default(false);
            $table->text('revoked_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shortcut_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('shortcut_key_hash');
            $table->string('device_fingerprint');
            $table->enum('shortcut_type', ['STAFF', 'SUPERADMIN']);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key_name')->unique(); // 'global'
            $table->json('blocked_ips')->nullable();
            $table->boolean('require_2fa')->default(false);
            $table->integer('session_timeout_minutes')->default(30);
            $table->string('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_settings');
        Schema::dropIfExists('shortcut_keys');
        Schema::dropIfExists('remember_tokens');
        Schema::dropIfExists('notifications');
    }
};
