<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a borrow request was cancelled and by whom.
 *
 * Every other lifecycle step already has its own timestamp; cancellation only
 * changed the status, so the request timeline had to fall back to updated_at,
 * which moves again on any later edit.
 *
 * Plain nullable columns and a foreign key, so the same migration runs on
 * PostgreSQL (hosted), MySQL (local) and SQLite (tests). Guarded so a failed
 * or partial run can be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('borrow_requests', 'cancelled_at')) {
            Schema::table('borrow_requests', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable()->after('expired_at');
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                    ->constrained('users')->nullOnDelete();
            });
        }

        // Backfill with the best information available. For cancelled rows the
        // last update is the cancellation itself unless the row was edited later.
        DB::table('borrow_requests')
            ->where('status', 'cancelled')
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at' => DB::raw('updated_at'),
                'cancelled_by' => DB::raw('updated_by'),
            ]);

        // Older records stored a student cancellation as a rejection.
        DB::table('borrow_requests')
            ->where('status', 'rejected')
            ->where('reject_reason', 'Request cancelled by student')
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at' => DB::raw('COALESCE(rejected_at, updated_at)'),
                'cancelled_by' => DB::raw('student_id'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('borrow_requests', 'cancelled_at')) {
            Schema::table('borrow_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropColumn('cancelled_at');
            });
        }
    }
};
