<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('replacement_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrow_request_id')->constrained('borrow_requests')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->string('item_name');
            $table->string('item_category')->nullable();
            $table->integer('quantity');
            $table->enum('type', ['missing', 'damaged']);
            $table->enum('status', ['pending', 'replaced'])->default('pending');
            $table->integer('amount'); // quantity to be replaced
            $table->integer('amount_paid')->default(0); // quantity already replaced
            $table->enum('resolution_type', ['replacement'])->nullable();
            $table->timestamp('resolution_date')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('payment_reference')->nullable(); // tracking number
            $table->timestamp('incident_date');
            $table->text('incident_notes')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_obligations');
    }
};
