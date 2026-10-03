<?php

namespace App\Services;

use App\Models\BorrowRequest;
use Carbon\Carbon;

class BorrowRequestExpiry
{
    /**
     * Close out requests whose booked day has fully passed without the student
     * collecting. Stock is only deducted at pickup(), so nothing needs restoring
     * here — the items never left the storeroom.
     *
     * No scheduler runs in this project, so callers run this on read paths.
     */
    public static function sweep(): void
    {
        $stale = BorrowRequest::whereIn('status', ['approved_instructor', 'ready_for_pickup'])
            ->whereNotNull('borrow_date')
            ->whereDate('borrow_date', '<', Carbon::today())
            ->with(['items', 'student'])
            ->get();

        foreach ($stale as $req) {
            $req->status = 'expired';
            $req->expired_at = Carbon::now();
            $req->save();

            NotificationService::notifyBorrowRequestLifecycle($req, 'expired');
        }
    }
}
