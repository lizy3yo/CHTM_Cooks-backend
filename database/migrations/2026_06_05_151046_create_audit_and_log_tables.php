<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deleted_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id');
            $table->json('item_data');
            $table->foreignId('deleted_by')->constrained('users')->onDelete('cascade');
            $table->string('deleted_by_name');
            $table->string('deleted_by_role');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('scheduled_deletion')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address')->nullable();
        });

        Schema::create('deleted_inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id');
            $table->json('category_data');
            $table->foreignId('deleted_by')->constrained('users')->onDelete('cascade');
            $table->string('deleted_by_name');
            $table->string('deleted_by_role');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('scheduled_deletion')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address')->nullable();
        });

        Schema::create('inventory_history', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->enum('entity_type', ['item', 'category']);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('user_name');
            $table->string('user_role');
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('timestamp')->useCurrent();
        });

        Schema::create('failed_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ip');
            $table->string('email');
            $table->string('reason');
            $table->string('risk');
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_login_attempts');
        Schema::dropIfExists('inventory_history');
        Schema::dropIfExists('deleted_inventory_categories');
        Schema::dropIfExists('deleted_inventory_items');
    }
};
