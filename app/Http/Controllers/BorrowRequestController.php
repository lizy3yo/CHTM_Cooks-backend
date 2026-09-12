<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BorrowRequest;
use App\Models\BorrowRequestItem;
use App\Models\InventoryItem;
use App\Models\ClassCode;
use App\Models\ReplacementObligation;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\AvailabilityService;
use App\Services\DeltaQuery;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;
use App\Services\ChangeSignatures;
use App\Services\StreamService;

class BorrowRequestController extends Controller
{
    private function transformUserSummary($user)
    {
        if (!$user)
            return null;
        return [
            'id' => (string) $user->id,
            'email' => $user->email,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'profilePhotoUrl' => $user->profile_photo_url,
            'fullName' => $user->first_name . ' ' . $user->last_name,
            'yearLevel' => $user->year_level,
            'block' => $user->block,
        ];
    }

    /**
     * @param BorrowRequest|\stdClass $req
     * @return array
     */
    private function transformBorrowRequest($req)
    {
        $items = $req->items->map(function ($item) {
            $inspection = null;
            if ($item->inspection_status) {
                $inspection = [
                    'status' => $item->inspection_status,
                    'inspectedAt' => $item->inspection_date ? $item->inspection_date->toIso8601String() : null,
                    'inspectedBy' => (string) $item->inspected_by,
                    'notes' => $item->inspection_notes,
                    'replacementQuantity' => $item->replacement_quantity,
                    'dueDate' => $item->due_date ? $item->due_date->toIso8601String() : null,
                ];
            }

            return [
                'itemId' => (string) $item->item_id,
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'category' => $item->category,
                'picture' => $item->picture,
                'inspection' => $inspection
            ];
        })->toArray();

        return [
            'id' => (string) $req->id,
            'studentId' => (string) $req->student_id,
            'instructorId' => $req->instructor_id ? (string) $req->instructor_id : null,
            'custodianId' => $req->custodian_id ? (string) $req->custodian_id : null,
            'classCodeId' => (string) $req->class_code_id,
            'student' => $this->transformUserSummary($req->student),
            'instructor' => $this->transformUserSummary($req->instructor),
            'custodian' => $this->transformUserSummary($req->custodian),
            'items' => $items,
            'purpose' => $req->purpose,
            'usageLocation' => $req->usage_location,
            'borrowDate' => $req->borrow_date ? $req->borrow_date->toIso8601String() : null,
            'returnDate' => $req->return_date ? $req->return_date->toIso8601String() : null,
            'status' => $req->status,
            'rejectReason' => $req->reject_reason,
            'rejectionNotes' => $req->rejection_notes,
            'appealReason' => $req->appeal_reason,
            'appealedAt' => $req->appealed_at ? $req->appealed_at->toIso8601String() : null,
            'appealCount' => (int) $req->appeal_count,
            'approvedAt' => $req->approved_at ? $req->approved_at->toIso8601String() : null,
            'rejectedAt' => $req->rejected_at ? $req->rejected_at->toIso8601String() : null,
            'releasedAt' => $req->released_at ? $req->released_at->toIso8601String() : null,
            'pickedUpAt' => $req->picked_up_at ? $req->picked_up_at->toIso8601String() : null,
            'returnedAt' => $req->returned_at ? $req->returned_at->toIso8601String() : null,
            'expiredAt' => $req->expired_at ? $req->expired_at->toIso8601String() : null,
            'missingAt' => $req->missing_at ? $req->missing_at->toIso8601String() : null,
            'resolvedAt' => $req->resolved_at ? $req->resolved_at->toIso8601String() : null,
            'lastReminderAt' => $req->last_reminder_at ? $req->last_reminder_at->toIso8601String() : null,
            'reminderCount' => (int) $req->reminder_count,
            'createdAt' => $req->created_at->toIso8601String(),
            'updatedAt' => $req->updated_at->toIso8601String(),
        ];
    }

    private function createNotification($userId, $role, $type, $title, $message, $requestId = null, $metadata = null)
    {
        Notification::create([
            'user_id' => $userId,
            'audience_role' => $role,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'borrow_request_id' => $requestId,
            'metadata' => $metadata,
            'is_read' => false
        ]);
    }

    /**
     * Close out requests whose booked day has fully passed without the student
     * collecting. Stock is only deducted at pickup(), so nothing needs restoring
     * here — the items never left the storeroom.
     */
    private function expireStaleRequests(): void
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

    public function list(Request $request)
    {
        // No scheduler runs in this project, so the sweep piggybacks on reads.
        $this->expireStaleRequests();

        $query = BorrowRequest::query()->with(['student', 'instructor', 'custodian', 'items']);

        $user = auth()->user();

        // Scope by user role
        if ($user->role === 'student') {
            $query->where('student_id', $user->id);
        } elseif ($user->role === 'instructor') {
            // Instructors see requests assigned to them, or requests from their class codes
            $classCodeIds = DB::table('class_code_instructor')->where('user_id', $user->id)->pluck('class_code_id');
            $query->where(function ($q) use ($user, $classCodeIds) {
                $q->where('instructor_id', $user->id)
                    ->orWhereIn('class_code_id', $classCodeIds);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('statuses')) {
            $statuses = explode(',', $request->statuses);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('search')) {
            $searchVal = trim((string) $request->search);
            $like = '%' . mb_strtolower($searchVal) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(purpose) LIKE ?', [$like])
                    ->orWhereHas('student', function ($sq) use ($like) {
                        $sq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                    });
            });
        }

        // `since` narrows this to rows the caller has not seen; `total` still
        // describes the whole visible set so the client can detect deletions.
        ['total' => $total, 'isDelta' => $isDelta] = DeltaQuery::apply($query, $request);

        $limit = $request->integer('limit', 20);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $sortBy = $request->input('sortBy', 'createdAt') === 'returnDate' ? 'return_date' : 'created_at';
        $requests = $query->orderBy($sortBy, 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'requests' => $requests->map(fn($r) => $this->transformBorrowRequest($r)),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages,
            'isDelta' => $isDelta,
            'syncedAt' => now()->toIso8601String()
        ]);
    }

    public function getById($id)
    {
        $req = BorrowRequest::with(['student', 'instructor', 'custodian', 'items'])->find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }
        return response()->json($this->transformBorrowRequest($req));
    }

    public function create(Request $request)
    {
        // Bookings open tomorrow and close at the end of the day after tomorrow.
        // The student form already enforces this window, but a stale page or a
        // direct API call would otherwise slip past it.
        $earliestBorrow = Carbon::today()->addDay()->toDateTimeString();
        $latestBorrow = Carbon::today()->addDays(2)->endOfDay()->toDateTimeString();

        $validator = Validator::make($request->all(), [
            'classCodeId' => 'required|integer|exists:class_codes,id',
            'purpose' => 'required|string',
            'usageLocation' => 'nullable|string|in:school,outdoor',
            'borrowDate' => [
                'required',
                'date',
                'after_or_equal:' . $earliestBorrow,
                'before_or_equal:' . $latestBorrow,
            ],
            'returnDate' => 'required|date|after:borrowDate',
            'items' => 'required|array',
            'items.*.itemId' => 'required|integer|exists:inventory_items,id',
            'items.*.quantity' => 'required|integer|min:1'
        ], [
            'borrowDate.after_or_equal' => 'Same-day requests are not allowed. Choose tomorrow or the day after.',
            'borrowDate.before_or_equal' => 'Requests can only be booked up to 2 days ahead.',
        ]);

        if ($validator->fails()) {
            // Surface the booking-window message directly — the client shows
            // `error`, so a generic "Validation failed" would hide the reason.
            $dateError = $validator->errors()->first('borrowDate');

            return response()->json([
                'error' => $dateError ?: 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $user = auth()->user();

        // Check if student has active pending requests
        $hasPending = BorrowRequest::where('student_id', $user->id)
            ->whereIn('status', ['pending_instructor', 'approved_instructor', 'ready_for_pickup', 'pending_return', 'pending_appeal'])
            ->exists();

        if ($hasPending) {
            return response()->json(['error' => 'You already have an active pending request awaiting action.'], 403);
        }

        // Check if student has active replacement obligations or unresolved missing/damaged items
        $hasUnpaidObligations = ReplacementObligation::where('student_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        $hasUnresolvedMissingRequest = BorrowRequest::where('student_id', $user->id)
            ->where('status', 'missing')
            ->exists();

        if ($hasUnpaidObligations || $hasUnresolvedMissingRequest) {
            return response()->json(['error' => 'You have outstanding replacement obligations for missing or damaged items. Please process and settle them before submitting new borrow requests.'], 403);
        }

        // Get class instructors to route the request to
        $class = ClassCode::with('instructors')->find($request->classCodeId);
        $instructorId = $class->instructors->first()?->id;

        // Availability is judged against the booked day, not today's shelf count:
        // units out on loan today may well be back by then, and units booked for
        // that day are unavailable even while still on the shelf.
        //
        // Every line is checked before anything is written — bailing out midway
        // would leave an empty pending request behind, which then blocks the
        // student from submitting again.
        $requestedIds = collect($request->items)->pluck('itemId')->map(fn ($id) => (int) $id)->all();
        $inventory = InventoryItem::whereIn('id', $requestedIds)->get();
        $bookedDate = Carbon::parse($request->borrowDate)->toDateString();
        $availability = AvailabilityService::forDates($inventory, [$bookedDate]);

        foreach ($request->items as $itemInput) {
            $invItem = $inventory->firstWhere('id', (int) $itemInput['itemId']);
            if (!$invItem) {
                return response()->json(['error' => 'One of the requested items no longer exists.'], 400);
            }

            $available = $availability[(int) $itemInput['itemId']][$bookedDate]['free'] ?? 0;
            if ($available < $itemInput['quantity'] && !$invItem->is_required) {
                return response()->json([
                    'error' => "Only {$available} × {$invItem->name} available on "
                        . Carbon::parse($bookedDate)->format('M j') . "."
                ], 400);
            }
        }

        $borrowRequest = BorrowRequest::create([
            'student_id' => $user->id,
            'instructor_id' => $instructorId,
            'class_code_id' => $request->classCodeId,
            'purpose' => $request->purpose,
            'usage_location' => $request->usageLocation ?? 'school',
            'borrow_date' => Carbon::parse($request->borrowDate),
            'return_date' => Carbon::parse($request->returnDate),
            'status' => 'pending_instructor',
            'created_by' => $user->id,
        ]);

        // Create borrow request items
        foreach ($request->items as $itemInput) {
            $invItem = $inventory->firstWhere('id', (int) $itemInput['itemId']);

            BorrowRequestItem::create([
                'borrow_request_id' => $borrowRequest->id,
                'item_id' => $invItem->id,
                'name' => $invItem->name,
                'quantity' => $itemInput['quantity'],
                'category' => $invItem->category,
                'picture' => $invItem->picture,
            ]);
        }

        // Send notifications
        NotificationService::notifyBorrowRequestLifecycle($borrowRequest, 'submitted');

        return response()->json($this->transformBorrowRequest($borrowRequest), 201);
    }

    /**
     * First item on the request that cannot be covered on its booked day.
     * Returns a ready-to-show message, or null when everything fits.
     *
     * Required items are exempt — they are always issued regardless of stock.
     */
    private function findAvailabilityShortfall(BorrowRequest $req): ?string
    {
        $date = Carbon::parse($req->borrow_date)->toDateString();
        $itemIds = $req->items->pluck('item_id')->map(fn ($id) => (int) $id)->all();

        if (empty($itemIds)) {
            return null;
        }

        $inventory = InventoryItem::whereIn('id', $itemIds)->get();
        // Exclude this request so it never counts against itself.
        $availability = AvailabilityService::forDates($inventory, [$date], $req->id);

        foreach ($req->items as $line) {
            $invItem = $inventory->firstWhere('id', $line->item_id);
            if (!$invItem || $invItem->is_required) {
                continue;
            }

            $free = $availability[(int) $line->item_id][$date]['free'] ?? 0;

            if ($free < $line->quantity) {
                return "Only {$free} × {$line->name} still free on "
                    . Carbon::parse($date)->format('M j')
                    . ". Another approved request has taken the rest.";
            }
        }

        return null;
    }

    /**
     * Per-item availability on this request's booked day, so an approver can see
     * what they are about to commit before they commit it.
     */
    public function availability($id)
    {
        $req = BorrowRequest::with('items')->find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        if (!$req->borrow_date) {
            return response()->json(['date' => null, 'items' => []]);
        }

        $date = Carbon::parse($req->borrow_date)->toDateString();
        $itemIds = $req->items->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        $inventory = InventoryItem::whereIn('id', $itemIds)->get();

        // Exclude this request so it does not count against itself.
        $availability = AvailabilityService::forDates($inventory, [$date], $req->id);

        return response()->json([
            'date' => $date,
            'items' => $req->items->map(function ($line) use ($availability, $date, $inventory) {
                $figures = $availability[(int) $line->item_id][$date] ?? null;
                $invItem = $inventory->firstWhere('id', $line->item_id);

                return [
                    'itemId' => (string) $line->item_id,
                    'name' => $line->name,
                    'requested' => (int) $line->quantity,
                    'free' => $figures['free'] ?? 0,
                    'owned' => $figures['owned'] ?? 0,
                    'delayed' => $figures['delayed'] ?? 0,
                    'isRequired' => (bool) ($invItem->is_required ?? false),
                ];
            })->values(),
        ]);
    }

    public function approve($id)
    {
        $req = BorrowRequest::with('items')->find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        // Pending requests hold no stock, so availability is settled here: the
        // first approval for a given day takes the units, later ones are told
        // there are none left.
        if ($req->borrow_date) {
            $shortfall = $this->findAvailabilityShortfall($req);
            if ($shortfall) {
                return response()->json(['error' => $shortfall], 409);
            }
        }

        $user = auth()->user();

        $req->status = 'approved_instructor';
        $req->approved_at = Carbon::now();
        $req->updated_by = $user->id;
        $req->save();

        // Notify student and custodians
        NotificationService::notifyBorrowRequestLifecycle($req, 'approved');

        return response()->json($this->transformBorrowRequest($req));
    }

    public function reject(Request $request, $id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Reason is required to reject request'], 400);
        }

        $user = auth()->user();

        $req->status = 'rejected';
        $req->reject_reason = $request->reason;
        $req->rejection_notes = $request->notes;
        $req->rejected_at = Carbon::now();
        $req->updated_by = $user->id;
        $req->save();

        // Notify student
        NotificationService::notifyBorrowRequestLifecycle($req, 'rejected', $request->reason . ($request->notes ? ". Notes: {$request->notes}" : ""));

        return response()->json($this->transformBorrowRequest($req));
    }

    public function cancel($id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $user = auth()->user();

        $req->status = 'cancelled';
        $req->updated_by = $user->id;
        $req->save();

        NotificationService::notifyBorrowRequestLifecycle($req, 'cancelled');

        return response()->json($this->transformBorrowRequest($req));
    }

    public function appeal(Request $request, $id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Appeal reason is required'], 400);
        }

        $targetDate = $req->borrow_date ?? $req->return_date;
        if ($targetDate && Carbon::parse($targetDate)->endOfDay()->isPast()) {
            return response()->json(['error' => 'Appeals are unavailable because the requested borrow date has passed.'], 400);
        }

        $user = auth()->user();

        $req->status = 'pending_appeal';
        $req->appeal_reason = $request->reason;
        $req->appealed_at = Carbon::now();
        $req->appeal_count += 1;
        $req->updated_by = $user->id;
        $req->save();

        // Notify instructor
        NotificationService::notifyBorrowRequestLifecycle($req, 'appealed', $request->reason);

        return response()->json($this->transformBorrowRequest($req));
    }

    public function release($id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $user = auth()->user();

        $req->status = 'ready_for_pickup';
        $req->released_at = Carbon::now();
        $req->custodian_id = $user->id;
        $req->updated_by = $user->id;
        $req->save();

        // Notify student
        NotificationService::notifyBorrowRequestLifecycle($req, 'ready_for_pickup');

        return response()->json($this->transformBorrowRequest($req));
    }

    public function pickup($id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $user = auth()->user();

        // Adjust stocks in inventory
        foreach ($req->items as $borrowItem) {
            $invItem = InventoryItem::find($borrowItem->item_id);
            if ($invItem) {
                // Deduct from quantity first, then donations
                $qtyToDeduct = $borrowItem->quantity;

                if ($invItem->quantity >= $qtyToDeduct) {
                    $invItem->decrement('quantity', $qtyToDeduct);
                } else {
                    $remainder = $qtyToDeduct - $invItem->quantity;
                    $invItem->quantity = 0;
                    $invItem->decrement('donations', $remainder);
                }

                $invItem->save();
            }
        }

        $req->status = 'borrowed';
        $req->picked_up_at = Carbon::now();
        $req->updated_by = $user->id;
        $req->save();

        // Notify student
        NotificationService::notifyBorrowRequestLifecycle($req, 'picked_up');

        return response()->json($this->transformBorrowRequest($req));
    }

    public function markReturned($id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $user = auth()->user();

        $req->status = 'pending_return';
        $req->returned_at = Carbon::now();
        $req->updated_by = $user->id;
        $req->save();

        // Notify custodians
        NotificationService::notifyBorrowRequestLifecycle($req, 'return_initiated');

        return response()->json($this->transformBorrowRequest($req));
    }

    public function markMissing($id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $user = auth()->user();

        $req->status = 'missing';
        $req->missing_at = Carbon::now();
        $req->updated_by = $user->id;
        $req->save();

        // Create replacement obligations for all items
        foreach ($req->items as $borrowItem) {
            ReplacementObligation::create([
                'borrow_request_id' => $req->id,
                'student_id' => $req->student_id,
                'item_id' => $borrowItem->item_id,
                'item_name' => $borrowItem->name,
                'item_category' => $borrowItem->category,
                'quantity' => $borrowItem->quantity,
                'type' => 'missing',
                'status' => 'pending',
                'amount' => $borrowItem->quantity,
                'amount_paid' => 0,
                'resolution_type' => 'replacement',
                'incident_date' => Carbon::now(),
                'incident_notes' => 'Marked missing during return process',
                'due_date' => Carbon::now()->addDays(7),
                'created_by' => $user->id,
            ]);
        }

        // Notify student
        NotificationService::notifyBorrowRequestLifecycle($req, 'missing');

        return response()->json($this->transformBorrowRequest($req));
    }

    public function sendOverdueReminder($id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $user = auth()->user();

        $req->last_reminder_at = Carbon::now();
        $req->reminder_count += 1;
        $req->save();

        // Send notification to student
        NotificationService::notifyBorrowRequestLifecycle($req, 'reminder_sent');

        return response()->json([
            'success' => true,
            'message' => 'Reminder sent successfully',
            'reminderCount' => $req->reminder_count
        ]);
    }

    public function inspectItems(Request $request, $id)
    {
        $req = BorrowRequest::find($id);
        if (!$req) {
            return response()->json(['error' => 'Borrow request not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.itemId' => 'required|integer',
            'items.*.status' => 'required|in:good,damaged,missing',
            'items.*.notes' => 'nullable|string',
            'items.*.replacementQuantity' => 'nullable|integer|min:0',
            'items.*.dueDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid inputs', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $obligationsCreated = 0;
        $hasIssues = false;

        $req->load(['student', 'instructor']);
        $studentName = $req->student ? ($req->student->first_name . ' ' . $req->student->last_name) : 'Unknown';
        $leaderName = $req->instructor ? ($req->instructor->first_name . ' ' . $req->instructor->last_name) : 'Unknown';
        $sessionDate = $req->borrow_date ? Carbon::parse($req->borrow_date)->format('Y-m-d') : 'Unknown';

        foreach ($request->items as $inspectInput) {
            $borrowItem = BorrowRequestItem::where('borrow_request_id', $req->id)
                ->where('item_id', $inspectInput['itemId'])
                ->first();

            if ($borrowItem) {
                $remarks = $inspectInput['notes'] ?? '';
                $formattedNotes = null;
                if ($inspectInput['status'] !== 'good') {
                    $formattedNotes = "Student: {$studentName} | Leader/Instructor: {$leaderName} | Lab Session Date: {$sessionDate} | Remarks: " . ($remarks ?: 'No remarks provided');
                }

                $borrowItem->inspection_status = $inspectInput['status'];
                $borrowItem->inspection_date = Carbon::now();
                $borrowItem->inspected_by = $user->id;
                $borrowItem->inspection_notes = $formattedNotes ?: ($remarks ?: null);
                $borrowItem->replacement_quantity = $inspectInput['replacementQuantity'] ?? null;
                $borrowItem->due_date = isset($inspectInput['dueDate']) ? Carbon::parse($inspectInput['dueDate']) : null;
                $borrowItem->save();

                $invItem = InventoryItem::find($inspectInput['itemId']);

                if ($inspectInput['status'] === 'good') {
                    // Return items back to inventory stock
                    if ($invItem) {
                        $invItem->increment('quantity', $borrowItem->quantity);
                    }
                } else {
                    $hasIssues = true;
                    // For damaged/missing items, create replacement obligations
                    $repQty = $inspectInput['replacementQuantity'] ?? $borrowItem->quantity;
                    if ($repQty > 0) {
                        ReplacementObligation::create([
                            'borrow_request_id' => $req->id,
                            'student_id' => $req->student_id,
                            'item_id' => $borrowItem->item_id,
                            'item_name' => $borrowItem->name,
                            'item_category' => $borrowItem->category,
                            'quantity' => $repQty,
                            'type' => $inspectInput['status'],
                            'status' => 'pending',
                            'amount' => $repQty,
                            'amount_paid' => 0,
                            'resolution_type' => 'replacement',
                            'incident_date' => Carbon::now(),
                            'incident_notes' => $formattedNotes ?: 'Damaged or missing during return inspection.',
                            'due_date' => isset($inspectInput['dueDate']) ? Carbon::parse($inspectInput['dueDate']) : Carbon::now()->addDays(7),
                            'created_by' => $user->id,
                        ]);
                        $obligationsCreated++;
                    }
                }
            }
        }

        $req->status = $hasIssues ? 'missing' : 'returned';
        $req->returned_at = Carbon::now();
        $req->updated_by = $user->id;
        $req->save();

        // Notify student
        if ($hasIssues) {
            NotificationService::notifyBorrowRequestLifecycle($req, 'item_issue');
        } else {
            NotificationService::notifyBorrowRequestLifecycle($req, 'returned');
        }

        return response()->json([
            'success' => true,
            'message' => 'Inspection saved successfully',
            'status' => $req->status,
            'obligationsCreated' => $obligationsCreated
        ]);
    }

    public function stream()
    {
        // Fingerprints are polled inside the stream and pushed the moment
        // they move; see StreamService for why the connection is short-lived.
        return StreamService::respond([
            'borrow_request_change' => [ChangeSignatures::class, 'borrowRequests'],
        ], auth()->user());
    }
}
