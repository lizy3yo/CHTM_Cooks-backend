<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('walk_in_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            // The real user, when the borrower is a registered student. Null for guests.
            $table->foreignId('student_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('student_name');
            // Displayed identifier (student's user id or an arbitrary guest ID).
            $table->string('student_identifier')->nullable();
            $table->string('email')->nullable();
            $table->string('class_code')->nullable();
            $table->text('purpose')->nullable();
            $table->enum('usage_location', ['school', 'outdoor'])->default('school');
            $table->timestamp('borrow_date')->nullable();
            $table->timestamp('return_date')->nullable();
            $table->enum('status', ['borrowed', 'returned', 'missing'])->default('borrowed');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('walk_in_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('walk_in_transaction_id')->constrained('walk_in_transactions')->onDelete('cascade');
            // Nullable so the record survives if the inventory item is later deleted.
            $table->foreignId('item_id')->nullable()->constrained('inventory_items')->onDelete('set null');
            $table->string('name');
            $table->string('category')->nullable();
            $table->integer('quantity');
            $table->enum('inspection_status', ['good', 'damaged', 'missing'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_in_transaction_items');
        Schema::dropIfExists('walk_in_transactions');
    }
};
