<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of a borrow request: one row per lifecycle step, with
 * the person who performed it.
 *
 * The request row only keeps one timestamp per step and a single custodian_id
 * (set at release), so the approver, the staff member who handed the items
 * over and the inspector were never recorded, and returned_at was overwritten
 * at inspection. The request timeline reads from here instead.
 *
 * The actor's name and role are copied onto the row so the history stays
 * readable after an account is renamed or removed.
 *
 * Plain columns and query-builder backfill, so the same migration runs on
 * PostgreSQL (hosted), MySQL (local) and SQLite (tests). Guarded so a failed
 * or partial run can be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('borrow_request_events')) {
            Schema::create('borrow_request_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrow_request_id')->constrained('borrow_requests')->cascadeOnDelete();
                $table->string('event', 40);
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name')->nullable();
                $table->string('actor_role', 20)->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at');

                $table->index(['borrow_request_id', 'occurred_at']);
            });
        }

        $this->backfill();
    }

    /**
     * Rebuild history for requests that predate this table from the timestamps
     * they already carry. The actor is filled in only where the request row
     * actually recorded one; the rest stay null rather than guessed.
     */
    private function backfill(): void
    {
        $users = DB::table('users')->get(['id', 'first_name', 'last_name', 'role'])->keyBy('id');

        $event = function ($requestId, string $name, $at, $actorId = null, ?string $notes = null, ?array $metadata = null) use ($users) {
            $actor = $actorId ? ($users[$actorId] ?? null) : null;

            return [
                'borrow_request_id' => $requestId,
                'event' => $name,
                'actor_id' => $actor ? $actor->id : null,
                'actor_name' => $actor ? trim($actor->first_name . ' ' . $actor->last_name) : null,
                'actor_role' => $actor ? $actor->role : null,
                'notes' => $notes,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'occurred_at' => $at,
            ];
        };

        DB::table('borrow_requests')
            ->whereNotIn('id', DB::table('borrow_request_events')->select('borrow_request_id'))
            ->orderBy('id')
            ->chunkById(200, function ($requests) use ($event) {
                $inspectors = DB::table('borrow_request_items')
                    ->whereIn('borrow_request_id', $requests->pluck('id'))
                    ->whereNotNull('inspected_by')
                    ->get(['borrow_request_id', 'inspected_by'])
                    ->keyBy('borrow_request_id');

                $rows = [];

                foreach ($requests as $r) {
                    $rows[] = $event($r->id, 'submitted', $r->created_at, $r->student_id);

                    if ($r->rejected_at) {
                        $rows[] = $event($r->id, 'rejected', $r->rejected_at, null, $r->reject_reason);
                    }
                    if ($r->appealed_at) {
                        $rows[] = $event($r->id, 'appealed', $r->appealed_at, $r->student_id, $r->appeal_reason);
                    }
                    if ($r->approved_at) {
                        $rows[] = $event($r->id, 'approved', $r->approved_at);
                    }
                    if ($r->released_at) {
                        // custodian_id is written by release(), so it is the real actor here.
                        $rows[] = $event($r->id, 'ready_for_pickup', $r->released_at, $r->custodian_id);
                    }
                    if ($r->picked_up_at) {
                        $rows[] = $event($r->id, 'picked_up', $r->picked_up_at);
                    }
                    if ($r->cancelled_at) {
                        $rows[] = $event($r->id, 'cancelled', $r->cancelled_at, $r->cancelled_by);
                    }
                    if ($r->expired_at) {
                        $rows[] = $event($r->id, 'expired', $r->expired_at);
                    }

                    $inspector = $inspectors[$r->id]->inspected_by ?? null;

                    if ($r->status === 'pending_return' && $r->returned_at) {
                        $rows[] = $event($r->id, 'return_initiated', $r->returned_at);
                    } elseif ($inspector && $r->returned_at) {
                        $rows[] = $event($r->id, 'inspected', $r->returned_at, $inspector, null, [
                            'outcome' => in_array($r->status, ['missing', 'resolved'], true) ? 'item_issue' : 'returned',
                        ]);
                    } elseif ($r->status === 'returned' && $r->returned_at) {
                        $rows[] = $event($r->id, 'inspected', $r->returned_at, null, null, ['outcome' => 'returned']);
                    }

                    if ($r->missing_at) {
                        $rows[] = $event($r->id, 'missing', $r->missing_at);
                    }
                    if ($r->resolved_at) {
                        $rows[] = $event($r->id, 'resolved', $r->resolved_at);
                    }
                }

                if ($rows) {
                    DB::table('borrow_request_events')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrow_request_events');
    }
};
