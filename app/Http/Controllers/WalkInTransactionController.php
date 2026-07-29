<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WalkInTransaction;
use App\Models\WalkInTransactionItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use DB;

class WalkInTransactionController extends Controller
{
    /** Roles allowed to view walk-in transactions. */
    private const VIEW_ROLES = ['instructor', 'custodian', 'superadmin', 'admin'];

    /** Roles allowed to record / return walk-in transactions. */
    private const MANAGE_ROLES = ['custodian', 'superadmin', 'admin'];

    private function transform(WalkInTransaction $t): array
    {
        return [
            // The reference is what the UI shows and keys on as the transaction id.
            'id' => $t->reference,
            'dbId' => (string) $t->id,
            'studentName' => $t->student_name,
            'studentId' => $t->student_identifier ?? '',
            'email' => $t->email ?? '',
            'classCode' => $t->class_code ?? '',
            'purpose' => $t->purpose ?? '',
            'usageLocation' => $t->usage_location,
            'borrowDate' => $t->borrow_date ? $t->borrow_date->toIso8601String() : null,
            'returnDate' => $t->return_date ? $t->return_date->toIso8601String() : null,
            'items' => $t->items->map(fn($i) => [
                'itemId' => $i->item_id ? (string) $i->item_id : '',
                'name' => $i->name,
                'quantity' => (int) $i->quantity,
                'category' => $i->category ?? '',
                'inspectionStatus' => $i->inspection_status,
            ])->values()->toArray(),
            'status' => $t->status,
            'returnedAt' => $t->returned_at ? $t->returned_at->toIso8601String() : null,
            'notes' => $t->notes,
            'createdAt' => $t->created_at->toIso8601String(),
        ];
    }

    private function generateReference(): string
    {
        do {
            $reference = 'W-' . strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 6));
        } while (WalkInTransaction::where('reference', $reference)->exists());
        return $reference;
    }

    /**
     * GET /api/walk-in-transactions
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::VIEW_ROLES)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = WalkInTransaction::with('items')->orderBy('created_at', 'desc');

        if ($request->filled('status') && in_array($request->status, ['borrowed', 'returned', 'missing'])) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'like', $search)
                  ->orWhere('student_identifier', 'like', $search)
                  ->orWhere('reference', 'like', $search);
            });
        }

        $limit = $request->integer('limit', 500);
        $transactions = $query->take($limit)->get();

        return response()->json([
            'walkIns' => $transactions->map(fn($t) => $this->transform($t)),
            'total' => $transactions->count(),
        ]);
    }

    /**
     * POST /api/walk-in-transactions
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::MANAGE_ROLES)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'studentName' => 'required|string|max:255',
            'studentId' => 'nullable|string|max:255',
            'studentUserId' => 'nullable|integer|exists:users,id',
            'email' => 'nullable|string|max:255',
            'classCode' => 'nullable|string|max:255',
            'purpose' => 'nullable|string',
            'usageLocation' => 'nullable|in:school,outdoor',
            'borrowDate' => 'nullable|date',
            'returnDate' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.itemId' => 'nullable',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.category' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        try {
            $transaction = DB::transaction(function () use ($request, $user) {
                $walkIn = WalkInTransaction::create([
                    'reference' => $this->generateReference(),
                    'student_id' => $request->input('studentUserId'),
                    'student_name' => $request->input('studentName'),
                    'student_identifier' => $request->input('studentId'),
                    'email' => $request->input('email'),
                    'class_code' => $request->input('classCode'),
                    'purpose' => $request->input('purpose') ?: 'Walk-in checkout',
                    'usage_location' => $request->input('usageLocation', 'school'),
                    'borrow_date' => $request->filled('borrowDate') ? Carbon::parse($request->input('borrowDate')) : Carbon::now(),
                    'return_date' => Carbon::parse($request->input('returnDate')),
                    'status' => 'borrowed',
                    'created_by' => $user->id,
                ]);

                foreach ($request->input('items') as $item) {
                    $itemId = $item['itemId'] ?? null;
                    $walkIn->items()->create([
                        'item_id' => is_numeric($itemId) ? (int) $itemId : null,
                        'name' => $item['name'],
                        'category' => $item['category'] ?? null,
                        'quantity' => (int) $item['quantity'],
                    ]);
                }

                return $walkIn->load('items');
            });

            return response()->json($this->transform($transaction), 201);
        } catch (\Exception $e) {
            Log::error('Failed to create walk-in transaction: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to record walk-in transaction'], 500);
        }
    }

    /**
     * POST /api/walk-in-transactions/{reference}/return
     */
    public function markReturned(Request $request, string $reference)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::MANAGE_ROLES)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $walkIn = WalkInTransaction::with('items')->where('reference', $reference)->first();
        if (!$walkIn) {
            return response()->json(['error' => 'Walk-in transaction not found'], 404);
        }
        if ($walkIn->status !== 'borrowed') {
            return response()->json(['error' => 'This transaction has already been closed'], 409);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:returned,missing',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.itemId' => 'nullable',
            'items.*.inspectionStatus' => 'nullable|in:good,damaged,missing',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        try {
            DB::transaction(function () use ($request, $walkIn) {
                $inspections = collect($request->input('items', []));
                $hasIssue = false;

                foreach ($walkIn->items as $item) {
                    $match = $inspections->first(fn($i) => (string) ($i['itemId'] ?? '') === (string) ($item->item_id ?? ''));
                    $status = $match['inspectionStatus'] ?? 'good';
                    $item->inspection_status = $status;
                    $item->save();
                    if ($status !== 'good') {
                        $hasIssue = true;
                    }
                }

                // Explicit status wins; otherwise derive from inspections.
                $finalStatus = $request->input('status') ?: ($hasIssue ? 'missing' : 'returned');
                $walkIn->status = $finalStatus;
                $walkIn->returned_at = Carbon::now();
                if ($request->filled('notes')) {
                    $walkIn->notes = $request->input('notes');
                }
                $walkIn->save();
            });

            return response()->json($this->transform($walkIn->fresh('items')));
        } catch (\Exception $e) {
            Log::error('Failed to process walk-in return: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process return'], 500);
        }
    }
}
