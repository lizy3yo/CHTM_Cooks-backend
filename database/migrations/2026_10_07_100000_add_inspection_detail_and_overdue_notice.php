<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a return inspection reportable.
 *
 * Until now the good / damaged / missing unit counts lived only inside the
 * free-text inspection note, so they could not be summed, and there was no
 * place for evidence photos. This stores them as real columns, records units
 * received beyond a replacement debt (a 1-for-3 replacement leaves 2 surplus),
 * and marks when the 3-day overdue notice was sent so it goes out once.
 *
 * Plain nullable columns and a query-builder backfill, so the same migration
 * runs on PostgreSQL (hosted), MySQL (local) and SQLite (tests). Guarded so a
 * failed or partial run can be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('borrow_request_items', 'good_quantity')) {
            Schema::table('borrow_request_items', function (Blueprint $table) {
                $table->unsignedInteger('good_quantity')->nullable();
                $table->unsignedInteger('damaged_quantity')->nullable();
                $table->unsignedInteger('missing_quantity')->nullable();
                $table->unsignedInteger('over_returned_quantity')->nullable();
                $table->text('inspection_remarks')->nullable();
                $table->json('inspection_photos')->nullable();
            });
        }

        if (!Schema::hasColumn('replacement_obligations', 'surplus_quantity')) {
            Schema::table('replacement_obligations', function (Blueprint $table) {
                $table->unsignedInteger('surplus_quantity')->default(0);
            });
        }

        if (!Schema::hasColumn('borrow_requests', 'overdue_notice_sent_at')) {
            Schema::table('borrow_requests', function (Blueprint $table) {
                $table->timestamp('overdue_notice_sent_at')->nullable();
            });
        }

        $this->backfillQuantities();
    }

    /**
     * Lift the counts out of notes written by the inspection checklist:
     * "[Quantities - Good: 3, Damaged: 1, Missing: 0] Over-return: +2 | Remarks: …".
     * Rows inspected before that format fall back to their single status.
     */
    private function backfillQuantities(): void
    {
        DB::table('borrow_request_items')
            ->whereNotNull('inspection_status')
            ->whereNull('good_quantity')
            ->orderBy('id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $quantity = (int) $item->quantity;
                    $notes = (string) $item->inspection_notes;

                    if (preg_match('/Good:\s*(\d+),\s*Damaged:\s*(\d+),\s*Missing:\s*(\d+)/i', $notes, $m)) {
                        [$good, $damaged, $missing] = [(int) $m[1], (int) $m[2], (int) $m[3]];
                    } else {
                        $affected = $item->inspection_status === 'good'
                            ? 0
                            : min($quantity, (int) ($item->replacement_quantity ?? $quantity));
                        $damaged = $item->inspection_status === 'damaged' ? $affected : 0;
                        $missing = $item->inspection_status === 'missing' ? $affected : 0;
                        $good = $quantity - $affected;
                    }

                    $over = preg_match('/Over-return:\s*\+(\d+)/i', $notes, $o) ? (int) $o[1] : 0;

                    // The checklist appends the custodian's own words after the
                    // last "Remarks:"; a tail starting with "[" is the counts.
                    $pos = strripos($notes, 'Remarks:');
                    $tail = $pos !== false ? trim(substr($notes, $pos + 8)) : '';
                    $remarks = ($tail !== '' && $tail[0] !== '[') ? $tail : null;

                    DB::table('borrow_request_items')->where('id', $item->id)->update([
                        'good_quantity' => max(0, $good),
                        'damaged_quantity' => max(0, $damaged),
                        'missing_quantity' => max(0, $missing),
                        'over_returned_quantity' => $over,
                        'inspection_remarks' => $remarks ?: null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('borrow_request_items', 'good_quantity')) {
            Schema::table('borrow_request_items', function (Blueprint $table) {
                $table->dropColumn([
                    'good_quantity', 'damaged_quantity', 'missing_quantity',
                    'over_returned_quantity', 'inspection_remarks', 'inspection_photos',
                ]);
            });
        }

        if (Schema::hasColumn('replacement_obligations', 'surplus_quantity')) {
            Schema::table('replacement_obligations', function (Blueprint $table) {
                $table->dropColumn('surplus_quantity');
            });
        }

        if (Schema::hasColumn('borrow_requests', 'overdue_notice_sent_at')) {
            Schema::table('borrow_requests', function (Blueprint $table) {
                $table->dropColumn('overdue_notice_sent_at');
            });
        }
    }
};
