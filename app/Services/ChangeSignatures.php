<?php

namespace App\Services;

use App\Models\BorrowRequest;
use App\Models\ClassCode;
use App\Models\Donation;
use App\Models\InventoryActivityLog;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\ReplacementObligation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WalkInTransaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cheap change fingerprints, one per realtime topic.
 *
 * A fingerprint is `MAX(updated_at):COUNT(*)` over the rows a topic cares about.
 * Both are index-backed, so a fingerprint costs far less than the list query a
 * client would otherwise run on a timer. When the fingerprint moves, something
 * changed and subscribers are told to refetch.
 *
 * Topics are scoped to the viewer wherever that is cheap to do: a student only
 * needs waking when their own requests change, not when anyone's do.
 */
class ChangeSignatures
{
    /**
     * @return array<string, callable(?User): string>  event name => fingerprint fn
     */
    public static function all(): array
    {
        return [
            'borrow_request_change' => [self::class, 'borrowRequests'],
            'inventory_change' => [self::class, 'inventory'],
            'donation_change' => [self::class, 'donations'],
            'replacement_obligation_change' => [self::class, 'replacementObligations'],
            'class_code_change' => [self::class, 'classCodes'],
            'notification_change' => [self::class, 'notifications'],
            'user_change' => [self::class, 'users'],
            'support_change' => [self::class, 'supportTickets'],
            'walk_in_change' => [self::class, 'walkIns'],
        ];
    }

    /**
     * Topics worth streaming to a given role. Every topic omitted here is one
     * less query per poll tick.
     *
     * @return array<string, callable(?User): string>
     */
    public static function forUser(?User $user): array
    {
        $all = self::all();
        $role = $user->role ?? 'student';

        // Walk-in transactions are a staff screen. Every other topic either
        // drives a student screen or is narrowed to the viewer further down.
        if ($role === 'student') {
            unset($all['walk_in_change']);
        }

        return $all;
    }

    /**
     * Every fingerprint this viewer watches, read once.
     *
     * `recent` closes the one gap a `MAX(updated_at)` fingerprint has. Stamps
     * have second precision, so a second write landing in the same second as
     * one already seen leaves the fingerprint unchanged. Any topic whose newest
     * row was stamped at or after the caller's previous read is therefore
     * reported again; the cost is an occasional redundant refetch, never a
     * missed update.
     *
     * @param  int|null  $after  Unix time of the caller's previous read.
     * @return array{signatures: array<string, string>, recent: array<int, string>, at: int}
     */
    public static function snapshot(?User $user, ?int $after = null, ?array $topics = null): array
    {
        $at = time();
        $signatures = [];
        $recent = [];

        foreach ($topics ?? self::forUser($user) as $event => $fingerprint) {
            try {
                $signatures[$event] = (string) $fingerprint($user);
            } catch (\Throwable $e) {
                // A dropped database connection must read as "unknown", never
                // surface as a broken response.
                $signatures[$event] = 'error';
                continue;
            }

            if ($after !== null && self::touchedSince($signatures[$event], $after, $at)) {
                $recent[] = $event;
            }
        }

        return ['signatures' => $signatures, 'recent' => $recent, 'at' => $at];
    }

    /**
     * Whether a fingerprint's newest row was stamped in the window
     * [`$since`, `$now`], both Unix times.
     *
     * The upper bound matters: a column stamped by the database in another
     * timezone can sit permanently "in the future", and without the bound it
     * would report a change on every single read.
     */
    public static function touchedSince(string $signature, int $since, ?int $now = null): bool
    {
        $newest = self::newestStamp($signature);

        if ($newest === null) {
            return false;
        }

        $now ??= time();

        return $newest >= date('Y-m-d H:i:s', $since)
            && $newest <= date('Y-m-d H:i:s', $now + 1);
    }

    /**
     * The latest `Y-m-d H:i:s` stamp inside a fingerprint, or null when the
     * topic has no rows. Composite fingerprints carry more than one.
     */
    public static function newestStamp(string $signature): ?string
    {
        if (!preg_match_all('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $signature, $matches)) {
            return null;
        }

        return max(array_map(fn (string $stamp) => str_replace('T', ' ', $stamp), $matches[0]));
    }

    public static function borrowRequests(?User $user): string
    {
        $query = BorrowRequest::query();

        // A student's screen only reacts to their own requests.
        if ($user && $user->role === 'student') {
            $query->where('student_id', $user->id);
        }

        return self::fingerprint($query);
    }

    public static function inventory(?User $user): string
    {
        return self::fingerprint(InventoryItem::query())
            . '|' . self::fingerprint(InventoryActivityLog::query(), 'timestamp');
    }

    public static function donations(?User $user): string
    {
        return self::fingerprint(Donation::query());
    }

    public static function replacementObligations(?User $user): string
    {
        $query = ReplacementObligation::query();

        if ($user && $user->role === 'student') {
            $query->where('student_id', $user->id);
        }

        return self::fingerprint($query);
    }

    public static function classCodes(?User $user): string
    {
        return self::fingerprint(ClassCode::query());
    }

    public static function notifications(?User $user): string
    {
        if (!$user) {
            return '0:0';
        }

        // Scoped to the viewer: a notification for someone else is not news.
        return self::fingerprint(Notification::query()->where('user_id', $user->id));
    }

    public static function users(?User $user): string
    {
        return self::fingerprint(User::query());
    }

    public static function supportTickets(?User $user): string
    {
        $query = SupportTicket::query();

        if ($user && $user->role === 'student') {
            $query->where('student_id', $user->id);
        }

        return self::fingerprint($query);
    }

    public static function walkIns(?User $user): string
    {
        return self::fingerprint(WalkInTransaction::query());
    }

    /**
     * `MAX(<column>):COUNT(*)` — moves on insert, update and delete alike.
     *
     * Both aggregates come back in a single round trip. This runs on every poll
     * tick for every connected client, so halving the query count here is worth
     * more than it looks.
     */
    private static function fingerprint(Builder $query, string $column = 'updated_at'): string
    {
        // Column names are internal, never user input, but keep the guard so a
        // future caller cannot turn this into an injection point.
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
            throw new \InvalidArgumentException("Unsafe fingerprint column: {$column}");
        }

        $row = $query->selectRaw("MAX({$column}) as fp_max, COUNT(*) as fp_count")->first();

        return ($row->fp_max ?: '0') . ':' . ($row->fp_count ?? 0);
    }
}
