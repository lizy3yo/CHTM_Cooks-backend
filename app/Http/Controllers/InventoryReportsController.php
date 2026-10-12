<?php

namespace App\Http\Controllers;

use App\Models\BorrowRequest;
use App\Models\BorrowRequestEvent;
use App\Models\BorrowRequestItem;
use App\Services\InventoryVariance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory reports for Reports & Analytics: the Over / Under variance and
 * the record of what came back from each loan.
 *
 * Staff see everything. Instructors see the variance (it is about the
 * storeroom) but only the returns of students in the classes they teach.
 */
class InventoryReportsController extends Controller
{
    /** Current photo of an inventory item, so report details can show it. */
    private static function pictureOf($itemId): ?string
    {
        static $pictures = null;
        $pictures ??= \App\Models\InventoryItem::withTrashed()->pluck('picture', 'id');

        return $itemId ? ($pictures[$itemId] ?? null) : null;
    }

    private const ALLOWED_ROLES = ['admin', 'custodian', 'superadmin', 'instructor'];

    private function forbidden()
    {
        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * GET /api/reports/inventory-variance?month=yyyy-mm
     */
    public function variance(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::ALLOWED_ROLES, true)) {
            return $this->forbidden();
        }

        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > Carbon::now()->format('Y-m')) {
            return response()->json(['error' => 'Choose a month up to the current one.'], 400);
        }

        // Housekeeping: closing last month must never block the report.
        try {
            InventoryVariance::closeDue();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            return response()->json(InventoryVariance::report($month));
        } catch (\Throwable $e) {
            Log::error('Inventory variance report failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to load the inventory variance.'], 500);
        }
    }

    /**
     * GET /api/reports/returned-items?from=yyyy-mm-dd&to=yyyy-mm-dd
     *
     * One row per inspected line: who had it, how long, and what came back.
     */
    public function returnedItems(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::ALLOWED_ROLES, true)) {
            return $this->forbidden();
        }

        try {
            $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::now()->endOfDay();
            $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : $to->copy()->startOfMonth();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid date range.'], 400);
        }

        $ids = fn (string $key) => array_values(array_filter((array) $request->query($key), fn ($v) => $v !== null && $v !== ''));

        $requests = BorrowRequest::query();
        if ($user->role === 'instructor') {
            $classIds = DB::table('class_code_instructor')->where('user_id', $user->id)->pluck('class_code_id');
            $requests->where(fn ($q) => $q->where('instructor_id', $user->id)->orWhereIn('class_code_id', $classIds));
        }
        if ($classCodes = $ids('class_code_id')) {
            $requests->whereIn('class_code_id', $classCodes);
        }
        if ($students = $ids('student_id')) {
            $requests->whereIn('student_id', $students);
        }

        try {
            $lines = BorrowRequestItem::with(['borrowRequest.student', 'inspector'])
                ->whereNotNull('inspection_status')
                ->whereBetween('inspection_date', [$from, $to])
                ->whereIn('borrow_request_id', $requests->select('id'))
                ->orderByDesc('inspection_date')
                ->limit(1000)
                ->get();

            // The student's own hand-back time, when it was recorded.
            $handedBack = BorrowRequestEvent::available()
                ? BorrowRequestEvent::whereIn('borrow_request_id', $lines->pluck('borrow_request_id')->unique())
                    ->where('event', 'return_initiated')
                    ->orderBy('occurred_at')
                    ->get()
                    ->keyBy('borrow_request_id')
                : collect();

            $hasDetail = Schema::hasColumn('borrow_request_items', 'good_quantity');

            $rows = $lines->map(function ($line) use ($handedBack, $hasDetail) {
                $req = $line->borrowRequest;
                $quantity = (int) $line->quantity;

                if ($hasDetail && $line->good_quantity !== null) {
                    [$good, $damaged, $missing, $over] = [
                        (int) $line->good_quantity, (int) $line->damaged_quantity,
                        (int) $line->missing_quantity, (int) $line->over_returned_quantity,
                    ];
                } else {
                    $affected = $line->inspection_status === 'good' ? 0 : min($quantity, (int) ($line->replacement_quantity ?? $quantity));
                    $damaged = $line->inspection_status === 'damaged' ? $affected : 0;
                    $missing = $line->inspection_status === 'missing' ? $affected : 0;
                    $good = $quantity - $affected;
                    $over = 0;
                }

                $returnedAt = isset($handedBack[$line->borrow_request_id])
                    ? $handedBack[$line->borrow_request_id]->occurred_at
                    : $line->inspection_date;
                $pickedUp = $req?->picked_up_at;
                $due = $req?->return_date;

                return [
                    'id' => (string) $line->id,
                    'requestId' => (string) $line->borrow_request_id,
                    'requestCode' => 'REQ-' . strtoupper(substr((string) $line->borrow_request_id, -6)),
                    'studentId' => $req ? (string) $req->student_id : null,
                    'studentName' => $req?->student ? trim($req->student->first_name . ' ' . $req->student->last_name) : 'Unknown Student',
                    'itemName' => $line->name,
                    'itemId' => (string) $line->item_id,
                    'picture' => self::pictureOf($line->item_id) ?? $line->picture,
                    'category' => $line->category,
                    'quantity' => $quantity,
                    'good' => $good,
                    'damaged' => $damaged,
                    'missing' => $missing,
                    'over' => $over,
                    'condition' => $line->inspection_status,
                    'pickedUpAt' => $pickedUp?->toIso8601String(),
                    'dueDate' => $due?->toIso8601String(),
                    'returnedAt' => $returnedAt?->toIso8601String(),
                    'inspectedAt' => $line->inspection_date?->toIso8601String(),
                    // The pickup day counts as day 1.
                    'daysKept' => $pickedUp && $returnedAt ? (int) floor($pickedUp->diffInDays($returnedAt, true)) + 1 : null,
                    'daysLate' => $due && $returnedAt && $returnedAt->gt($due)
                        ? (int) ceil($due->diffInHours($returnedAt, true) / 24)
                        : 0,
                    'inspectedBy' => $line->inspector ? trim($line->inspector->first_name . ' ' . $line->inspector->last_name) : null,
                    'remarks' => $hasDetail ? $line->inspection_remarks : null,
                    'photos' => $hasDetail ? ($line->inspection_photos ?? []) : [],
                    'replacementDue' => $line->due_date?->toIso8601String(),
                ];
            })->values();

            $kept = $rows->pluck('daysKept')->filter(fn ($d) => $d !== null);
            $byRequest = $rows->groupBy('requestId');

            return response()->json([
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'summary' => [
                    'lines' => $rows->count(),
                    'requests' => $byRequest->count(),
                    'borrowed' => $rows->sum('quantity'),
                    'good' => $rows->sum('good'),
                    'damaged' => $rows->sum('damaged'),
                    'missing' => $rows->sum('missing'),
                    'over' => $rows->sum('over'),
                    'lateRequests' => $byRequest->filter(fn ($g) => $g->max('daysLate') > 0)->count(),
                    'onTimeRequests' => $byRequest->filter(fn ($g) => $g->max('daysLate') === 0)->count(),
                    'avgDaysKept' => $kept->count() ? round($kept->avg(), 1) : null,
                ],
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            Log::error('Returned items report failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to load returned items.'], 500);
        }
    }
}
