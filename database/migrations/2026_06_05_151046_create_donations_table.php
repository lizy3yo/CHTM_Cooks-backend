<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->string('donor_name');
            $table->string('item_name');
            $table->integer('quantity');
            $table->string('unit')->nullable();
            $table->text('purpose');
            $table->timestamp('date')->nullable();
            $table->text('notes')->nullable();
            $table->enum('inventory_action', ['new_item', 'add_to_existing']);
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
    }
};
