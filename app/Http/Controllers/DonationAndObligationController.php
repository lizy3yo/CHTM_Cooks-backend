<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Donation;
use Illuminate\Support\Facades\Schema;
use App\Models\ReplacementObligation;
use App\Models\InventoryItem;
use App\Models\InventoryCategory;
use App\Models\BorrowRequest;
use App\Models\BorrowRequestEvent;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;
use App\Services\ChangeSignatures;
use App\Services\InventoryVariance;
use App\Services\StreamService;

class DonationAndObligationController extends Controller
{
    // ==========================================
    // DONATIONS TRANSFORMATION & CRUD
    // ==========================================

    /**
     * @param \App\Models\Donation|\stdClass $donation
     * @return array
     */
    private function transformDonation($donation)
    {
        return [
            'id' => (string) $donation->id,
            'receiptNumber' => $donation->receipt_number,
            'donorName' => $donation->donor_name,
            'itemName' => $donation->item_name,
            'quantity' => (int) $donation->quantity,
            'unit' => $donation->unit,
            'purpose' => $donation->purpose,
            'date' => $donation->date ? $donation->date->toIso8601String() : null,
            'notes' => $donation->notes,
            'inventoryAction' => $donation->inventory_action,
            'inventoryItemId' => $donation->inventory_item_id ? (string) $donation->inventory_item_id : null,
            // Photo of the donated inventory item.
            'picture' => $donation->item?->picture,
            // Staff member who logged the donation (shown in the details window).
            'recordedBy' => $donation->creator ? trim($donation->creator->first_name . ' ' . $donation->creator->last_name) : null,
            // Declared value: the cost of one unit, and of the whole donation.
            'unitCost' => $donation->unit_cost !== null ? (float) $donation->unit_cost : null,
            'totalValue' => $donation->unit_cost !== null ? round((float) $donation->unit_cost * (int) $donation->quantity, 2) : null,
            // Deed of donation: pending until the signed copy is uploaded.
            'deedStatus' => $donation->deed_status ?? 'pending',
            'deedFileUrl' => $donation->deed_file_url,
            'deedSignedAt' => $donation->deed_signed_at ? $donation->deed_signed_at->toIso8601String() : null,
            'createdAt' => $donation->created_at->toIso8601String(),
            'updatedAt' => $donation->updated_at->toIso8601String(),
        ];
    }

    public function getDonations(Request $request)
    {
        $query = Donation::query()->with(['item', 'creator']);

        if ($request->filled('search')) {
            $searchVal = trim((string) $request->search);
            $like = '%' . mb_strtolower($searchVal) . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(receipt_number) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(donor_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(item_name) LIKE ?', [$like]);
            });
        }

        $total = $query->count();
        $limit = $request->integer('limit', 50);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $donations = $query->orderBy('created_at', 'desc')
                           ->skip(($page - 1) * $limit)
                           ->take($limit)
                           ->get();

        return response()->json([
            'donations' => $donations->map(fn($d) => $this->transformDonation($d)),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ]);
    }

    public function getDonationById($id)
    {
        $donation = Donation::find($id);
        if (!$donation) {
            return response()->json(['error' => 'Donation record not found'], 404);
        }
        return response()->json([
            'donation' => $this->transformDonation($donation)
        ]);
    }

    public function createDonation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'inventoryAction' => 'required|in:new_item,add_to_existing',
            'donorName' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'unit' => 'nullable|string|max:50',
            'purpose' => 'required|string',
            'date' => 'required|date',
            'notes' => 'nullable|string',
            'unitCost' => 'nullable|numeric|min:0|max:9999999999',
            
            // Required if new_item
            'itemName' => 'required_if:inventoryAction,new_item|string|max:255',
            'category' => 'required_if:inventoryAction,new_item|string',
            'categoryId' => 'nullable|integer',
            'specification' => 'nullable|string',
            'toolsOrEquipment' => 'nullable|string',
            'picture' => 'nullable|string',
            
            // Required if add_to_existing
            'inventoryItemId' => 'required_if:inventoryAction,add_to_existing|integer|exists:inventory_items,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $receiptNumber = 'DON-' . date('Ymd') . '-' . mt_rand(1000, 9999);

        // Ensure receipt number is unique
        while (Donation::where('receipt_number', $receiptNumber)->exists()) {
            $receiptNumber = 'DON-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        }

        $inventoryItemId = null;
        $itemName = $request->itemName;

        if ($request->inventoryAction === 'new_item') {
            // Create a new inventory item representing the donation
            $invItem = InventoryItem::create([
                'name' => $request->itemName,
                'category' => $request->category,
                'category_id' => $request->categoryId,
                'specification' => $request->specification,
                'tools_or_equipment' => $request->toolsOrEquipment ?? '',
                'picture' => $request->picture ?? null,
                'quantity' => 0,
                'unit_price' => $request->unitCost,
                'donations' => $request->quantity,
                // Donated stock is new this month, so it does not raise the
                // starting count: it shows as Over until the month is closed.
                'eom_count' => InventoryVariance::available() ? 0 : $request->quantity,
                'created_by' => $user->id,
            ]);

            // Increment category count
            if ($invItem->category_id) {
                InventoryCategory::where('id', $invItem->category_id)->increment('item_count');
            }

            $inventoryItemId = $invItem->id;
        } else {
            // Add quantity to existing inventory item
            $invItem = InventoryItem::find($request->inventoryItemId);
            $invItem->increment('donations', $request->quantity);
            if (!InventoryVariance::available()) {
                $invItem->eom_count += $request->quantity;
                $invItem->save();
            }

            $inventoryItemId = $invItem->id;
            $itemName = $invItem->name;
        }

        $donation = Donation::create([
            'receipt_number' => $receiptNumber,
            'donor_name' => $request->donorName,
            'item_name' => $itemName,
            'quantity' => $request->quantity,
            'unit' => $request->unit,
            'purpose' => $request->purpose,
            'date' => Carbon::parse($request->date),
            'notes' => $request->notes,
            'inventory_action' => $request->inventoryAction,
            'inventory_item_id' => $inventoryItemId,
            'created_by' => $user->id,
        ] + (self::tracksDonationValue() ? ['unit_cost' => $request->unitCost] : []));

        return response()->json($this->transformDonation($donation), 201);
    }

    public function addDonationQuantity(Request $request, $id)
    {
        $donation = Donation::find($id);
        if (!$donation) {
            return response()->json(['error' => 'Donation record not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantityToAdd' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid quantity', 'details' => $validator->errors()], 400);
        }

        $qtyToAdd = $request->quantityToAdd;

        // Increment donation
        $donation->increment('quantity', $qtyToAdd);
        if ($request->filled('notes')) {
            $donation->notes = ($donation->notes ? $donation->notes . "\n" : "") . "Added: " . $request->notes;
            $donation->save();
        }

        // Increment inventory item donations
        if ($donation->inventory_item_id) {
            $invItem = InventoryItem::find($donation->inventory_item_id);
            if ($invItem) {
                $invItem->increment('donations', $qtyToAdd);
                if (!InventoryVariance::available()) {
                    $invItem->eom_count += $qtyToAdd;
                    $invItem->save();
                }
            }
        }

        return response()->json($this->transformDonation($donation));
    }

    public function updateDonation(Request $request, $id)
    {
        $donation = Donation::find($id);
        if (!$donation) {
            return response()->json(['error' => 'Donation record not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'donorName' => 'sometimes|required|string|max:255',
            'purpose' => 'sometimes|required|string',
            'date' => 'sometimes|required|date',
            'notes' => 'nullable|string',
            'quantity' => 'sometimes|required|integer|min:1',
            'unitCost' => 'nullable|numeric|min:0|max:9999999999'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $oldQty = $donation->quantity;

        $updateData = [];
        if ($request->has('donorName')) $updateData['donor_name'] = $request->donorName;
        if ($request->has('purpose')) $updateData['purpose'] = $request->purpose;
        if ($request->has('date')) $updateData['date'] = Carbon::parse($request->date);
        if ($request->has('notes')) $updateData['notes'] = $request->notes;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('unitCost') && self::tracksDonationValue()) $updateData['unit_cost'] = $request->unitCost;

        $donation->update($updateData);

        // Re-adjust stock if quantity changed
        if ($request->has('quantity') && $oldQty != $request->quantity) {
            $diff = $request->quantity - $oldQty;
            if ($donation->inventory_item_id) {
                $invItem = InventoryItem::find($donation->inventory_item_id);
                if ($invItem) {
                    $invItem->increment('donations', $diff);
                    if (!InventoryVariance::available()) {
                        $invItem->eom_count += $diff;
                        $invItem->save();
                    }
                }
            }
        }

        return response()->json($this->transformDonation($donation));
    }

    public function deleteDonation($id)
    {
        $donation = Donation::find($id);
        if (!$donation) {
            return response()->json(['error' => 'Donation record not found'], 404);
        }

        // Subtract quantity from inventory
        if ($donation->inventory_item_id) {
            $invItem = InventoryItem::find($donation->inventory_item_id);
            if ($invItem) {
                $invItem->decrement('donations', min($donation->quantity, $invItem->donations));
            }
        }

        $donation->delete();

        return response()->json(['success' => true, 'message' => 'Donation deleted successfully']);
    }

    /** Roles that record and manage donations (admins view only). */
    private const DONATION_MANAGERS = ['custodian', 'instructor', 'superadmin'];

    private static ?bool $tracksDonationValue = null;

    /** False until the cost-and-deed migration has run. */
    private static function tracksDonationValue(): bool
    {
        return self::$tracksDonationValue ??= Schema::hasColumn('donations', 'unit_cost');
    }

    private function newReceiptNumber(): string
    {
        do {
            $receiptNumber = 'DON-' . date('Ymd') . '-' . mt_rand(1000, 9999);
        } while (Donation::where('receipt_number', $receiptNumber)->exists());

        return $receiptNumber;
    }

    /**
     * POST /api/donations/{id}/deed
     *
     * Attach the signed deed of donation. The file itself is uploaded by the
     * client first; this records where it is and marks the deed as signed.
     */
    public function attachDeed(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::DONATION_MANAGERS, true)) {
            return response()->json(['error' => 'You do not have permission to perform this action.'], 403);
        }

        $donation = Donation::find($id);
        if (!$donation) {
            return response()->json(['error' => 'Donation record not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'fileUrl' => 'required|url|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'Upload the signed deed first.', 'details' => $validator->errors()], 400);
        }

        $donation->update([
            'deed_status' => 'signed',
            'deed_file_url' => $request->fileUrl,
            'deed_signed_at' => Carbon::now(),
            'deed_uploaded_by' => $user->id,
        ]);

        return response()->json($this->transformDonation($donation->fresh()));
    }

    /**
     * POST /api/donations/import
     *
     * Record many donations at once from a spreadsheet the client has already
     * parsed. Every row is checked before anything is written, and the rows
     * are saved together: a sheet with one bad row changes nothing.
     *
     * A row whose item name matches an inventory item adds to it; otherwise
     * the item is created.
     */
    public function importDonations(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::DONATION_MANAGERS, true)) {
            return response()->json(['error' => 'You do not have permission to perform this action.'], 403);
        }

        $rows = $request->input('rows');
        if (!is_array($rows) || count($rows) === 0) {
            return response()->json(['error' => 'The file has no donation rows.'], 400);
        }
        if (count($rows) > 500) {
            return response()->json(['error' => 'Import up to 500 donations at a time.'], 400);
        }

        $errors = [];
        foreach ($rows as $index => $row) {
            $validator = Validator::make(is_array($row) ? $row : [], [
                'donorName' => 'required|string|max:255',
                'itemName' => 'required|string|max:255',
                'quantity' => 'required|integer|min:1',
                'unitCost' => 'nullable|numeric|min:0|max:9999999999',
                'date' => 'nullable|date',
                'purpose' => 'nullable|string|max:1000',
                'notes' => 'nullable|string|max:1000',
                'category' => 'nullable|string|max:255',
                'unit' => 'nullable|string|max:50',
            ], [], [
                'donorName' => 'donor', 'itemName' => 'item', 'unitCost' => 'unit cost',
            ]);

            foreach ($validator->errors()->all() as $message) {
                // Row numbers match the spreadsheet: row 1 is the header.
                $errors[] = ['row' => $index + 2, 'message' => $message];
            }
        }

        if ($errors) {
            return response()->json([
                'error' => 'Some rows need fixing. Nothing was imported.',
                'rowErrors' => array_slice($errors, 0, 100),
            ], 422);
        }

        $summary = DB::transaction(function () use ($rows, $user) {
            $created = 0;
            $newItems = 0;
            $units = 0;
            $value = 0.0;
            $tracksValue = self::tracksDonationValue();
            $baselineFollows = !InventoryVariance::available();

            foreach ($rows as $row) {
                $name = trim($row['itemName']);
                $quantity = (int) $row['quantity'];
                $unitCost = isset($row['unitCost']) && $row['unitCost'] !== '' ? (float) $row['unitCost'] : null;

                $item = InventoryItem::where('archived', false)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first();

                if ($item) {
                    $item->increment('donations', $quantity);
                    if ($baselineFollows) {
                        $item->increment('eom_count', $quantity);
                    }
                    $action = 'add_to_existing';
                } else {
                    $categoryName = trim((string) ($row['category'] ?? '')) ?: 'General';
                    $category = InventoryCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])->first();

                    $item = InventoryItem::create([
                        'name' => $name,
                        'category' => $category->name ?? $categoryName,
                        'category_id' => $category->id ?? null,
                        'tools_or_equipment' => '',
                        'quantity' => 0,
                        'unit_price' => $unitCost,
                        'donations' => $quantity,
                        'eom_count' => $baselineFollows ? $quantity : 0,
                        'created_by' => $user->id,
                    ]);
                    if ($category) {
                        $category->increment('item_count');
                    }
                    $action = 'new_item';
                    $newItems++;
                }

                Donation::create(array_merge([
                    'receipt_number' => $this->newReceiptNumber(),
                    'donor_name' => trim($row['donorName']),
                    'item_name' => $item->name,
                    'quantity' => $quantity,
                    'unit' => $row['unit'] ?? null,
                    'purpose' => trim((string) ($row['purpose'] ?? '')) ?: 'Donation',
                    'date' => !empty($row['date']) ? Carbon::parse($row['date']) : Carbon::now(),
                    'notes' => $row['notes'] ?? null,
                    'inventory_action' => $action,
                    'inventory_item_id' => $item->id,
                    'created_by' => $user->id,
                ], $tracksValue ? ['unit_cost' => $unitCost] : []));

                $created++;
                $units += $quantity;
                $value += ($unitCost ?? 0) * $quantity;
            }

            return ['imported' => $created, 'newItems' => $newItems, 'units' => $units, 'totalValue' => round($value, 2)];
        });

        return response()->json(array_merge(['success' => true], $summary));
    }

    public function streamDonations()
    {
        // Fingerprints are polled inside the stream and pushed the moment
        // they move; see StreamService for why the connection is short-lived.
        return StreamService::respond([
            'donation_change' => [ChangeSignatures::class, 'donations'],
        ], auth()->user());
    }

    // ==========================================
    // REPLACEMENT OBLIGATIONS CRUD
    // ==========================================

    /**
     * @param \App\Models\ReplacementObligation|\stdClass $ob
     * @return array
     */
    private function transformObligation($ob)
    {
        $student = $ob->student;
        $balance = max(0, $ob->amount - $ob->amount_paid);

        return [
            'id' => (string) $ob->id,
            'borrowRequestId' => (string) $ob->borrow_request_id,
            'studentId' => (string) $ob->student_id,
            'studentName' => $student ? $student->first_name . ' ' . $student->last_name : 'Unknown Student',
            'studentEmail' => $student ? $student->email : '',
            'studentProfilePhotoUrl' => $student ? $student->profile_photo_url : null,
            'itemId' => (string) $ob->item_id,
            'itemName' => $ob->item_name,
            'itemCategory' => $ob->item_category,
            'quantity' => (int) $ob->quantity,
            'type' => $ob->type,
            'status' => $ob->status,
            'amount' => (int) $ob->amount,
            'amountPaid' => (int) $ob->amount_paid,
            'surplusQuantity' => (int) ($ob->surplus_quantity ?? 0),
            'balance' => $balance,
            'resolutionType' => $ob->resolution_type,
            'resolutionDate' => $ob->resolution_date ? $ob->resolution_date->toIso8601String() : null,
            'resolutionNotes' => $ob->resolution_notes,
            'paymentReference' => $ob->payment_reference,
            'incidentDate' => $ob->incident_date->toIso8601String(),
            'incidentNotes' => $ob->incident_notes,
            'dueDate' => $ob->due_date ? $ob->due_date->toIso8601String() : null,
            'createdAt' => $ob->created_at->toIso8601String(),
            'updatedAt' => $ob->updated_at->toIso8601String(),
        ];
    }

    public function getObligations(Request $request)
    {
        $query = ReplacementObligation::query()->with('student');

        $user = auth()->user();

        // Scope by user
        if ($user->role === 'student') {
            $query->where('student_id', $user->id);
        } elseif ($request->filled('studentId')) {
            $query->where('student_id', $request->studentId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $total = $query->count();
        $limit = $request->integer('limit', 50);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $obligations = $query->orderBy('created_at', 'desc')
                             ->skip(($page - 1) * $limit)
                             ->take($limit)
                             ->get();

        return response()->json([
            'obligations' => $obligations->map(fn($o) => $this->transformObligation($o)),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ]);
    }

    public function getObligationById($id)
    {
        $ob = ReplacementObligation::with('student')->find($id);
        if (!$ob) {
            return response()->json(['error' => 'Replacement obligation not found'], 404);
        }
        return response()->json([
            'obligation' => $this->transformObligation($ob)
        ]);
    }

    public function resolveObligation(Request $request, $id)
    {
        $ob = ReplacementObligation::find($id);
        if (!$ob) {
            return response()->json(['error' => 'Replacement obligation not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'resolutionType' => 'required|in:replacement',
            'amountPaid' => 'sometimes|required|integer|min:1',
            'resolutionNotes' => 'nullable|string',
            'paymentReference' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $remaining = max(0, $ob->amount - $ob->amount_paid);
        $amountPaidInput = $request->integer('amountPaid', $remaining);

        // A custodian may accept more than was owed (one broken glass replaced
        // with three). Only the debt counts as paid; the rest is surplus stock.
        $tracksSurplus = Schema::hasColumn('replacement_obligations', 'surplus_quantity');
        $settled = $tracksSurplus ? min($amountPaidInput, $remaining) : $amountPaidInput;
        $surplus = $amountPaidInput - $settled;

        $ob->amount_paid += $settled;
        if ($tracksSurplus) {
            $ob->surplus_quantity += $surplus;
        }
        $ob->resolution_type = $request->resolutionType;
        $ob->resolution_notes = $request->resolutionNotes;
        $ob->payment_reference = $request->paymentReference;
        $ob->updated_by = $user->id;

        if ($ob->amount_paid >= $ob->amount) {
            $ob->status = 'replaced';
            $ob->resolution_date = Carbon::now();
        }
        $ob->save();

        // Increment stock in inventory since item is replaced/returned
        if ($ob->item_id) {
            $invItem = InventoryItem::find($ob->item_id);
            if ($invItem) {
                $invItem->increment('quantity', $amountPaidInput);
            }
        }

        // Reconcile borrow request status
        $this->reconcileRequest($ob->borrow_request_id);

        return response()->json([
            'success' => true,
            'message' => 'Replacement obligation updated successfully',
            'settled' => $settled,
            'surplus' => $surplus
        ]);
    }

    public function reconcile()
    {
        $reconciled = 0;
        $requests = BorrowRequest::where('status', 'missing')->get();

        foreach ($requests as $req) {
            if ($this->reconcileRequest($req->id)) {
                $reconciled++;
            }
        }

        return response()->json(['reconciled' => $reconciled]);
    }

    private function reconcileRequest($borrowRequestId)
    {
        $req = BorrowRequest::find($borrowRequestId);
        if (!$req) return false;

        // Check if there are any pending replacement obligations left
        $hasPending = ReplacementObligation::where('borrow_request_id', $borrowRequestId)
            ->where('status', 'pending')
            ->exists();

        // If no pending obligations, transition status to resolved
        if (!$hasPending && $req->status === 'missing') {
            $req->status = 'resolved';
            $req->resolved_at = Carbon::now();
            $req->save();

            BorrowRequestEvent::record($req, 'resolved', auth()->user());

            return true;
        }

        return false;
    }

    public function streamObligations()
    {
        // Fingerprints are polled inside the stream and pushed the moment
        // they move; see StreamService for why the connection is short-lived.
        return StreamService::respond([
            'replacement_obligation_change' => [ChangeSignatures::class, 'replacementObligations'],
        ], auth()->user());
    }
}
