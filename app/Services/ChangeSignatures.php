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
        ];
    }

    /**
     * Topics worth streaming to a given role. Students have no business being
     * woken by staff-only tables, and every topic omitted here is one less
     * query per poll tick.
     *
     * @return array<string, callable(?User): string>
     */
    public static function forUser(?User $user): array
    {
        $all = self::all();
        $role = $user->role ?? 'student';

        if ($role === 'student') {
            return array_intersect_key($all, array_flip([
                'borrow_request_change',
                'inventory_change',
                'replacement_obligation_change',
                'notification_change',
                'class_code_change',
                'support_change',
                // Drives the profile page; the users table changes rarely, so
                // watching it globally costs almost nothing.
                'user_change',
            ]));
        }

        if ($role === 'instructor') {
            return array_intersect_key($all, array_flip([
                'borrow_request_change',
                'inventory_change',
                'replacement_obligation_change',
                'notification_change',
                'class_code_change',
                'support_change',
                // Drives the profile page; the users table changes rarely, so
                // watching it globally costs almost nothing.
                'user_change',
            ]));
        }

        // Custodians, admins and superadmins see everything.
        return $all;
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
