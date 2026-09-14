<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the 'expired' borrow request status and its timestamp.
 *
 * Production runs PostgreSQL, where Laravel's enum() is a varchar guarded by a
 * CHECK constraint rather than a native ENUM type, and ->change() cannot alter
 * that constraint. Each driver therefore widens the allowed values its own way.
 *
 * Both steps are idempotent so a previously failed or partial run can be
 * retried safely.
 */
return new class extends Migration
{
    private const BASE_STATUSES = [
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
    ];

    public function up(): void
    {
        $this->setAllowedStatuses([...self::BASE_STATUSES, 'expired']);

        if (!Schema::hasColumn('borrow_requests', 'expired_at')) {
            Schema::table('borrow_requests', function (Blueprint $table) {
                $table->timestamp('expired_at')->nullable()->after('returned_at');
            });
        }
    }

    public function down(): void
    {
        // Expired requests collapse back into 'cancelled' so the narrowed status list stays valid.
        DB::table('borrow_requests')->where('status', 'expired')->update(['status' => 'cancelled']);

        $this->setAllowedStatuses(self::BASE_STATUSES);

        if (Schema::hasColumn('borrow_requests', 'expired_at')) {
            Schema::table('borrow_requests', function (Blueprint $table) {
                $table->dropColumn('expired_at');
            });
        }
    }

    private function setAllowedStatuses(array $statuses): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $allowed = implode(', ', array_map(fn ($s) => "'{$s}'", $statuses));

            DB::statement('ALTER TABLE borrow_requests DROP CONSTRAINT IF EXISTS borrow_requests_status_check');
            DB::statement("ALTER TABLE borrow_requests ADD CONSTRAINT borrow_requests_status_check CHECK (status IN ({$allowed}))");
        } else {
            // MySQL/MariaDB/SQLite: the schema builder rewrites the column definition.
            Schema::table('borrow_requests', function (Blueprint $table) use ($statuses) {
                $table->enum('status', $statuses)->default('pending_instructor')->change();
            });
        }
    }
};
