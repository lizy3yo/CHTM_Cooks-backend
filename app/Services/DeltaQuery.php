<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Incremental list reads.
 *
 * A client that already holds a list does not need it sent again when one row
 * changes. Passing `?since=<ISO timestamp>` narrows the result to rows touched
 * after that moment, and the client merges them into what it already has.
 *
 * Two rules make this safe:
 *
 *  1. `total` is always counted BEFORE the `since` filter, so it reflects every
 *     row the caller can see. A client comparing totals can therefore notice
 *     that rows disappeared.
 *  2. Deletions are invisible to a `since` query — a deleted row is simply
 *     absent. That is exactly what the total is for: when it drops, the client
 *     falls back to a full read rather than silently keeping a stale row.
 */
class DeltaQuery
{
    /**
     * Apply an optional `since` filter, returning the pre-filter total.
     *
     * Call after every other filter (role scoping, search, status) and before
     * ordering and pagination.
     *
     * @return array{total:int, isDelta:bool}
     */
    public static function apply(Builder $query, Request $request, string $column = 'updated_at'): array
    {
        // Counted first: `total` must describe the whole visible set, not the
        // changed slice, or deletion detection breaks on the client.
        $total = $query->count();

        $since = self::parseSince($request);

        if ($since !== null) {
            // `>=`, not `>`. Timestamps here have second precision, so a row
            // written in the same second the watermark was taken would be
            // skipped forever by a strict comparison. The inclusive bound
            // re-sends at most one second's worth of rows, and the client
            // merges by id, so re-sending a row it already has is harmless.
            $query->where($column, '>=', $since);

            return ['total' => $total, 'isDelta' => true];
        }

        return ['total' => $total, 'isDelta' => false];
    }

    /**
     * An unparseable or absent `since` means "send everything" — a malformed
     * timestamp must never be read as "nothing changed".
     */
    public static function parseSince(Request $request): ?Carbon
    {
        $raw = trim((string) $request->input('since', ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
