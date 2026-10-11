<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One step in a borrow request's history, and who performed it.
 * Append-only: rows are written by record() and never edited.
 */
class BorrowRequestEvent extends Model
{
    protected $table = 'borrow_request_events';

    public $timestamps = false;

    protected $fillable = [
        'borrow_request_id',
        'event',
        'actor_id',
        'actor_name',
        'actor_role',
        'notes',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function borrowRequest(): BelongsTo
    {
        return $this->belongsTo(BorrowRequest::class, 'borrow_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    private static ?bool $available = null;

    /**
     * False until the migration has run. Deploys only migrate when
     * RUN_MIGRATIONS is set, and the request list must keep working either way.
     */
    public static function available(): bool
    {
        return static::$available ??= Schema::hasTable('borrow_request_events');
    }

    /**
     * Log a lifecycle step. `$actor` is null for system actions such as expiry.
     *
     * History must never block the action it describes, so a failed write is
     * reported and swallowed.
     */
    public static function record(BorrowRequest $request, string $event, ?User $actor = null, ?string $notes = null, ?array $metadata = null): void
    {
        if (!static::available()) {
            return;
        }

        try {
            static::create([
                'borrow_request_id' => $request->id,
                'event' => $event,
                'actor_id' => $actor?->id,
                'actor_name' => $actor ? trim($actor->first_name . ' ' . $actor->last_name) : null,
                'actor_role' => $actor?->role,
                'notes' => $notes,
                'metadata' => $metadata,
                'occurred_at' => Carbon::now(),
            ]);

            // Drop any cached relation so the response includes this event.
            $request->unsetRelation('events');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
