<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE borrow_requests MODIFY COLUMN status ENUM(
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
            'pending_appeal',
            'expired'
        ) NOT NULL DEFAULT 'pending_instructor'");

        Schema::table('borrow_requests', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('borrow_requests', function (Blueprint $table) {
            $table->dropColumn('expired_at');
        });

        // Expired requests collapse back into 'cancelled' so the narrowed enum stays valid.
        DB::statement("UPDATE borrow_requests SET status = 'cancelled' WHERE status = 'expired'");

        DB::statement("ALTER TABLE borrow_requests MODIFY COLUMN status ENUM(
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
        ) NOT NULL DEFAULT 'pending_instructor'");
    }
};
