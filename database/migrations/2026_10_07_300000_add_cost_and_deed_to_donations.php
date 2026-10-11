<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a donation a declared value and a deed of donation.
 *
 * `unit_cost` is the declared cost of one unit, so reports can state how much
 * was donated. The deed is printed from the record, signed on paper and the
 * signed copy uploaded, which moves `deed_status` from pending to signed.
 *
 * Plain nullable columns, so the same migration runs on PostgreSQL (hosted),
 * MySQL (local) and SQLite (tests). Guarded so a failed run can be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('donations', 'unit_cost')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->decimal('unit_cost', 12, 2)->nullable();
                // A plain string rather than an enum: enum changes need
                // driver-specific SQL on PostgreSQL.
                $table->string('deed_status', 10)->default('pending');
                $table->text('deed_file_url')->nullable();
                $table->timestamp('deed_signed_at')->nullable();
                $table->foreignId('deed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donations', 'unit_cost')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('deed_uploaded_by');
                $table->dropColumn(['unit_cost', 'deed_status', 'deed_file_url', 'deed_signed_at']);
            });
        }
    }
};
