<?php

namespace App\Http\Controllers;

use App\Models\BorrowRequest;
use App\Models\BorrowRequestItem;
use App\Models\ReplacementObligation;
use App\Services\BorrowRequestExpiry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Live operational snapshot for the staff dashboards (admin, custodian,
 * superadmin).
 *
 * Unlike the analytics report, nothing here is scoped to a date range: every
 * figure describes the state of the storeroom right now, so a request created
 * last month that is still borrowed today is counted.
 */
class OperationsDashboardController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'custodian', 'superadmin'];

    /** Rows returned per queue; totals are always exact. */
    private const QUEUE_PREVIEW_LIMIT = 5;

    /**
     * Stage → stored statuses. Appeals are awaiting the instructor again, so
     * they sit in Under Review alongside first-time requests.
     */
    private const STAGES = [
        'underReview' => ['pending_instructor', 'pending_appeal'],
        'approved' => ['approved_instructor'],
        'readyForPickup' => ['ready_for_pickup'],
        'borrowed' => ['borrowed'],
        'pendingReturn' => ['pending_return'],
        'unresolved' => ['missing'],
    ];

    /**
     * GET /api/dashboard/operations
     */
    public function overview()
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Housekeeping only: a failure must never block the dashboard.
        try {
            BorrowRequestExpiry::sweep();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $now = Carbon::now();

            $byStatus = BorrowRequest::query()
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($n) => (int) $n);

            $pipeline = [];
            foreach (self::STAGES as $stage => $statuses) {
                $pipeline[$stage] = $byStatus->only($statuses)->sum();
            }
            $pipeline['appeals'] = (int) ($byStatus['pending_appeal'] ?? 0);
            $pipeline['overdue'] = BorrowRequest::overdue($now)->count();

            $itemsOut = (int) BorrowRequestItem::query()
                ->whereHas('borrowRequest', fn ($q) => $q->whereIn('status', BorrowRequest::OUT_STATUSES))
                ->sum('quantity');

            return response()->json([
                'pipeline' => $pipeline,
                'itemsOut' => $itemsOut,
                'replacementsPending' => ReplacementObligation::where('status', 'pending')->count(),
                'queues' => [
                    // Oldest first: a review queue is worked first-in, first-out.
                    'underReview' => $this->queue(self::STAGES['underReview'], 'created_at', $now),
                    // Soonest booking first: that is the order items must be prepared in.
                    'approved' => $this->queue(self::STAGES['approved'], 'borrow_date', $now),
                    'readyForPickup' => $this->queue(self::STAGES['readyForPickup'], 'borrow_date', $now),
                    // Earliest due first, which also puts overdue requests on top.
                    'borrowed' => $this->queue(BorrowRequest::OUT_STATUSES, 'return_date', $now),
                ],
                'generatedAt' => $now->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to build operations overview: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load dashboard overview'], 500);
        }
    }

    private function queue(array $statuses, string $orderBy, Carbon $now): array
    {
        return BorrowRequest::with(['student', 'instructor'])
            ->withCount('items')
            ->whereIn('status', $statuses)
            ->orderBy($orderBy, 'asc')
            ->limit(self::QUEUE_PREVIEW_LIMIT)
            ->get()
            ->map(fn (BorrowRequest $r) => [
                'id' => (string) $r->id,
                'status' => $r->status,
                'studentId' => (string) $r->student_id,
                'studentName' => $r->student
                    ? trim($r->student->first_name . ' ' . $r->student->last_name)
                    : null,
                'studentPhotoUrl' => $r->student?->profile_photo_url,
                'instructorName' => $r->instructor
                    ? trim($r->instructor->first_name . ' ' . $r->instructor->last_name)
                    : null,
                'itemCount' => (int) $r->items_count,
                'borrowDate' => $r->borrow_date?->toIso8601String(),
                'returnDate' => $r->return_date?->toIso8601String(),
                'createdAt' => $r->created_at?->toIso8601String(),
                'isOverdue' => in_array($r->status, BorrowRequest::OUT_STATUSES, true)
                    && $r->return_date !== null
                    && $r->return_date->lt($now),
            ])
            ->values()
            ->all();
    }
}
