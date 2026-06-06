<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Donation;
use App\Models\ReplacementObligation;
use App\Models\InventoryItem;
use App\Models\InventoryCategory;
use App\Models\BorrowRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;

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
            'createdAt' => $donation->created_at->toIso8601String(),
            'updatedAt' => $donation->updated_at->toIso8601String(),
        ];
    }

    public function getDonations(Request $request)
    {
        $query = Donation::query()->with('item');

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', $search)
                  ->orWhere('donor_name', 'like', $search)
                  ->orWhere('item_name', 'like', $search);
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
            
            // Required if new_item
            'itemName' => 'required_if:inventoryAction,new_item|string|max:255',
            'category' => 'required_if:inventoryAction,new_item|string',
            'categoryId' => 'nullable|integer',
            'specification' => 'nullable|string',
            'toolsOrEquipment' => 'required_if:inventoryAction,new_item|string',
            
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
                'tools_or_equipment' => $request->toolsOrEquipment,
                'picture' => null,
                'quantity' => 0,
                'donations' => $request->quantity,
                'eom_count' => $request->quantity,
                'status' => 'In Stock',
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
            $invItem->eom_count += $request->quantity;
            
            // Update stock status
            $totalStock = $invItem->quantity + $invItem->donations;
            if ($totalStock > 5) {
                $invItem->status = 'In Stock';
            } elseif ($totalStock > 0) {
                $invItem->status = 'Low Stock';
            }
            $invItem->save();

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
        ]);

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
                $invItem->eom_count += $qtyToAdd;
                
                $totalStock = $invItem->quantity + $invItem->donations;
                if ($totalStock > 5) {
                    $invItem->status = 'In Stock';
                } elseif ($totalStock > 0) {
                    $invItem->status = 'Low Stock';
                }
                $invItem->save();
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
            'quantity' => 'sometimes|required|integer|min:1'
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

        $donation->update($updateData);

        // Re-adjust stock if quantity changed
        if ($request->has('quantity') && $oldQty != $request->quantity) {
            $diff = $request->quantity - $oldQty;
            if ($donation->inventory_item_id) {
                $invItem = InventoryItem::find($donation->inventory_item_id);
                if ($invItem) {
                    $invItem->increment('donations', $diff);
                    $invItem->eom_count += $diff;
                    
                    $totalStock = $invItem->quantity + $invItem->donations;
                    if ($totalStock > 5) {
                        $invItem->status = 'In Stock';
                    } elseif ($totalStock > 0) {
                        $invItem->status = 'Low Stock';
                    } else {
                        $invItem->status = 'Out of Stock';
                    }
                    $invItem->save();
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
                
                $totalStock = $invItem->quantity + $invItem->donations;
                if ($totalStock === 0) {
                    $invItem->status = 'Out of Stock';
                } elseif ($totalStock <= 5) {
                    $invItem->status = 'Low Stock';
                }
                $invItem->save();
            }
        }

        $donation->delete();

        return response()->json(['success' => true, 'message' => 'Donation deleted successfully']);
    }

    public function streamDonations()
    {
        return new StreamedResponse(function () {
            echo "event: connected\n";
            echo "data: {}\n\n";
            ob_flush();
            flush();

            // Heartbeat
            $start = time();
            while (time() - $start < 30) {
                echo "event: heartbeat\n";
                echo "data: {}\n\n";
                ob_flush();
                flush();
                sleep(10);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
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
        $amountPaidInput = $request->integer('amountPaid', $ob->amount - $ob->amount_paid);

        $ob->amount_paid += $amountPaidInput;
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
                $invItem->status = 'In Stock';
                $invItem->save();
            }
        }

        // Reconcile borrow request status
        $this->reconcileRequest($ob->borrow_request_id);

        return response()->json([
            'success' => true,
            'message' => 'Replacement obligation updated successfully'
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
            return true;
        }

        return false;
    }

    public function streamObligations()
    {
        return new StreamedResponse(function () {
            echo "event: connected\n";
            echo "data: {}\n\n";
            ob_flush();
            flush();

            // Heartbeat
            $start = time();
            while (time() - $start < 30) {
                echo ": keepalive\n\n";
                ob_flush();
                flush();
                sleep(15);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
