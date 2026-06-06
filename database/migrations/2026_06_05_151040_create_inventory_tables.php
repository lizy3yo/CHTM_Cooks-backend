<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('picture')->nullable();
            $table->integer('item_count')->default(0);
            $table->boolean('archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->foreignId('category_id')->nullable()->constrained('inventory_categories')->onDelete('set null');
            $table->text('specification')->nullable();
            $table->string('tools_or_equipment');
            $table->string('picture')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('donations')->default(0);
            $table->integer('eom_count')->default(0);
            $table->text('description')->nullable();
            $table->enum('status', ['In Stock', 'Low Stock', 'Out of Stock', 'Archived'])->default('In Stock');
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('max_quantity_per_request')->nullable();
            $table->boolean('archived')->default(false);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_categories');
    }
};
