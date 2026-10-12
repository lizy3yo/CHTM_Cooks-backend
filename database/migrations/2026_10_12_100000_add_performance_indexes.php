<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns every list, dashboard and realtime fingerprint
 * filters or sorts on.
 *
 * MySQL indexes a foreign key column on its own; PostgreSQL does not. On the
 * hosted database that left the per-student request list, the notification
 * bell and every `MAX(updated_at)` change fingerprint scanning whole tables —
 * and the fingerprints run every couple of seconds for every connected user.
 *
 * Each index is created on its own and skipped when an equivalent one already
 * exists, so this is safe to run on any driver and safe to re-run.
 */
return new class extends Migration
{
    /**
     * PostgreSQL aborts the whole transaction on the first failed statement.
     * Running outside one keeps a single unexpected failure from taking every
     * other index — and the deploy — down with it.
     */
    public $withinTransaction = false;

    /** @var array<string, array<int, array<int, string>>>  table => list of column sets */
    private const INDEXES = [
        'borrow_requests' => [
            ['student_id', 'created_at'],
            ['instructor_id'],
            ['class_code_id'],
            ['status', 'return_date'],
            ['status', 'borrow_date'],
            ['updated_at'],
            ['created_at'],
        ],
        'borrow_request_items' => [
            ['borrow_request_id'],
            ['item_id'],
        ],
        'replacement_obligations' => [
            ['student_id', 'status'],
            ['borrow_request_id'],
            ['item_id'],
            ['status'],
            ['updated_at'],
        ],
        'notifications' => [
            ['user_id', 'created_at'],
            ['user_id', 'is_read'],
            ['user_id', 'updated_at'],
        ],
        'inventory_items' => [
            ['category_id'],
            ['archived', 'deleted_at'],
            ['updated_at'],
        ],
        'inventory_history' => [
            ['timestamp'],
            ['entity_type', 'entity_id'],
            ['user_id'],
        ],
        'donations' => [
            ['inventory_item_id'],
            ['deed_id'],
            ['updated_at'],
        ],
        'class_codes' => [
            ['updated_at'],
        ],
        // The composite primary keys lead with class_code_id, so "which classes
        // is this person in" had nothing to use.
        'class_code_instructor' => [
            ['user_id'],
        ],
        'class_code_student' => [
            ['user_id'],
        ],
        'users' => [
            ['role', 'is_active'],
            ['updated_at'],
        ],
        'support_tickets' => [
            ['student_id'],
            ['updated_at'],
        ],
        'support_ticket_messages' => [
            ['support_ticket_id'],
        ],
        'walk_in_transactions' => [
            ['student_id'],
            ['status'],
            ['updated_at'],
        ],
        'walk_in_transaction_items' => [
            ['walk_in_transaction_id'],
            ['item_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columnSets) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columnSets as $columns) {
                $this->addIndex($table, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columnSets) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($columnSets as $columns) {
                $name = $this->indexName($table, $columns);

                try {
                    if (Schema::hasIndex($table, $name)) {
                        Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** @param array<int, string> $columns */
    private function addIndex(string $table, array $columns): void
    {
        try {
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    return;
                }
            }

            if ($this->isCovered($table, $columns)) {
                return;
            }

            $name = $this->indexName($table, $columns);
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Throwable $e) {
            // An index is an optimisation. Missing one must never block a deploy.
            report($e);
        }
    }

    /**
     * True when an existing index already starts with these columns, in this
     * order — it serves the same lookups, so a second one would only slow writes.
     *
     * @param array<int, string> $columns
     */
    private function isCovered(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            $existing = array_map('strtolower', $index['columns'] ?? []);

            if (array_slice($existing, 0, count($columns)) === array_map('strtolower', $columns)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $columns */
    private function indexName(string $table, array $columns): string
    {
        // Kept well under PostgreSQL's 63-character identifier limit.
        return substr('perf_' . $table . '_' . implode('_', $columns), 0, 60);
    }
};
