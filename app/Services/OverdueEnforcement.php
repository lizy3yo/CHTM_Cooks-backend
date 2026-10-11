<?php

namespace App\Services;

use App\Models\BorrowRequest;
use App\Models\BorrowRequestEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The 3-day rule: items still out three days after their return date earn the
 * student a disciplinary notice (email and in-app), and the student cannot
 * borrow again until the items are back.
 */
class OverdueEnforcement
{
    /** Days past the return date before the notice goes out. */
    public const GRACE_DAYS = 3;

    private static ?bool $available = null;

    /** False until the migration has run; see BorrowRequestEvent::available(). */
    private static function available(): bool
    {
        return static::$available ??= Schema::hasColumn('borrow_requests', 'overdue_notice_sent_at');
    }

    private static function cutoff(): Carbon
    {
        return Carbon::now()->subDays(self::GRACE_DAYS);
    }

    /**
     * Send the notice for every request that has crossed the line and has not
     * had one. Runs from the scheduler, and from read paths as a fallback.
     *
     * @return int notices sent
     */
    public static function sweep(): int
    {
        if (!static::available()) {
            return 0;
        }

        $due = BorrowRequest::overdue()
            ->where('return_date', '<', self::cutoff())
            ->whereNull('overdue_notice_sent_at')
            ->with(['items', 'student'])
            ->get();

        $sent = 0;

        foreach ($due as $req) {
            // Claim the request first: two sweeps running at once must not
            // both send the notice.
            $claimed = BorrowRequest::where('id', $req->id)
                ->whereNull('overdue_notice_sent_at')
                ->update(['overdue_notice_sent_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            if (!$claimed) {
                continue;
            }

            $daysOverdue = (int) floor($req->return_date->diffInDays(Carbon::now(), true));

            BorrowRequestEvent::record($req, 'overdue_notice', null, null, ['daysOverdue' => $daysOverdue]);

            NotificationService::notifyBorrowRequestLifecycle(
                $req,
                'overdue_notice',
                'Due ' . $req->return_date->format('M j, Y') . " · {$daysOverdue} days overdue. "
                    . 'You cannot submit new borrow requests until these items are returned.'
            );

            $sent++;
        }

        return $sent;
    }

    /**
     * The request that currently bars this student from borrowing, if any.
     * Judged from the dates, not the notice flag, so the block holds even if
     * the sweep has not run yet.
     */
    public static function blockingRequest(int $studentId): ?BorrowRequest
    {
        return BorrowRequest::overdue()
            ->where('student_id', $studentId)
            ->where('return_date', '<', self::cutoff())
            ->orderBy('return_date')
            ->first();
    }
}
