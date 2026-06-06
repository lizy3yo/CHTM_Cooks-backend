<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('borrow_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('instructor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('custodian_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('class_code_id')->constrained('class_codes')->onDelete('cascade');
            $table->text('purpose');
            $table->enum('usage_location', ['school', 'outdoor'])->nullable();
            $table->timestamp('borrow_date')->nullable();
            $table->timestamp('return_date')->nullable();
            $table->enum('status', [
                'pending_instructor',
                'approved_instructor',
                'ready_for_pickup',
                'borrowed',
                'pending_return',
                'missing',
                'resolved',
                'returned',
                'cancelled',
                'rejected',
                'pending_appeal'
            ])->default('pending_instructor');
            $table->text('reject_reason')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->text('appeal_reason')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->integer('appeal_count')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('missing_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('borrow_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrow_request_id')->constrained('borrow_requests')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->string('name');
            $table->integer('quantity');
            $table->string('category')->nullable();
            $table->string('picture')->nullable();
            
            // Return inspection details
            $table->enum('inspection_status', ['good', 'damaged', 'missing'])->nullable();
            $table->timestamp('inspection_date')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('inspection_notes')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->timestamp('due_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrow_request_items');
        Schema::dropIfExists('borrow_requests');
    }
};
