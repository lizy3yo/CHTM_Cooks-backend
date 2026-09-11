<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Walk-in returns now use the same inspection checklist as student borrow
     * requests, so each item keeps the same inspection details that
     * borrow_request_items already store (remarks, replacement qty, due date),
     * plus any units returned beyond what was borrowed.
     */
    public function up(): void
    {
        Schema::table('walk_in_transaction_items', function (Blueprint $table) {
            $table->text('inspection_notes')->nullable()->after('inspection_status');
            $table->integer('replacement_quantity')->nullable()->after('inspection_notes');
            $table->timestamp('due_date')->nullable()->after('replacement_quantity');
            $table->integer('additional_returned')->default(0)->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_transaction_items', function (Blueprint $table) {
            $table->dropColumn(['inspection_notes', 'replacement_quantity', 'due_date', 'additional_returned']);
        });
    }
};
