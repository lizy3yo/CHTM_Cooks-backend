<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryActivityLog;
use App\Models\DeletedInventoryItem;
use App\Models\DeletedInventoryCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;
use App\Services\StorageService;

class InventoryController extends Controller
{
    /**
     * Helper to log activities in audit trail
     */
    private function logActivity($action, $entityType, $entityId, $entityName, $changes = null, $metadata = null)
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        InventoryActivityLog::create([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'user_id' => $user->id,
            'user_name' => $user->first_name . ' ' . $user->last_name,
            'user_role' => $user->role,
            'changes' => $changes,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => Carbon::now(),
        ]);
    }

    /**
     * Transform Item to frontend representation
     * @param InventoryItem|\stdClass $item
     * @return array
     */
    private function transformItem($item)
    {
        $released = (int) \App\Models\BorrowRequestItem::where('item_id', $item->id)
            ->whereHas('borrowRequest', function ($q) {
                $q->whereIn('status', ['borrowed', 'pending_return', 'pending_appeal']);
            })
            ->sum('quantity');
            
        $available = (int) (($item->quantity ?? 0) + ($item->donations ?? 0));

        return [
            'id' => (string) $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'categoryId' => $item->category_id ? (string) $item->category_id : null,
            'specification' => $item->specification,
            'toolsOrEquipment' => $item->tools_or_equipment,
            'picture' => $item->picture,
            'quantity' => (int) $item->quantity,
            'donations' => (int) $item->donations,
            'eomCount' => (int) $item->eom_count,
            'released' => $released,
            'available' => $available,
            'currentCount' => $available + $released, // Total stock = in storage + released
            'variance' => (int) ($available - ($item->eom_count ?? 0)),
            'description' => $item->description,
            'status' => $item->status,
            'unitPrice' => $item->unit_price ? (float) $item->unit_price : null,
            'isrequired' => (bool) $item->is_required,
            'maxQuantityPerRequest' => $item->max_quantity_per_request,
            'archived' => (bool) $item->archived,
            'createdAt' => $item->created_at->toIso8601String(),
            'updatedAt' => $item->updated_at->toIso8601String(),
        ];
    }

    /**
     * Transform Category to frontend representation
     * @param InventoryCategory|\stdClass $category
     * @return array
     */
    private function transformCategory($category)
    {
        return [
            'id' => (string) $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'picture' => $category->picture,
            'itemCount' => (int) $category->item_count,
            'archived' => (bool) $category->archived,
            'createdAt' => $category->created_at->toIso8601String(),
            'updatedAt' => $category->updated_at->toIso8601String(),
        ];
    }

    // ==========================================
    // CATALOG (public read-only unified endpoint)
    // ==========================================

    /**
     * GET /api/inventory/catalog
     *
     * Unified, read-only catalog endpoint consumed by the student borrow flow.
     * Returns paginated items together with all active categories and a
     * summary block in a single request, matching the CatalogResponse contract.
     *
     * Query parameters:
     *   search       – full-text filter on name / specification
     *   category     – category name (case-insensitive)
     *   availability – all | available | borrowed | maintenance | outofstock
     *   required     – all | required
     *   sortBy       – name | category | availability | recent | updated
     *   page         – 1-based page index (default 1)
     *   limit        – items per page (default 50, max 200)
     */
    public function getCatalog(Request $request)
    {
        // ── Parameters ──────────────────────────────────────────────────────
        $search       = $request->input('search');
        $category     = $request->input('category');
        $availability = $request->input('availability', 'all');
        $required     = $request->input('required', 'all');
        $sortBy       = $request->input('sortBy', 'name');
        $page         = max(1, (int) $request->input('page', 1));
        $limit        = min(200, max(1, (int) $request->input('limit', 50)));

        // ── Items query ──────────────────────────────────────────────────────
        $query = InventoryItem::query()->where('archived', false);

        if ($search) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('specification', 'like', $like)
                  ->orWhere('description', 'like', $like);
            });
        }

        if ($category && $category !== 'all') {
            $query->where('category', 'like', '%' . $category . '%');
        }

        if ($required === 'required') {
            $query->where('is_required', true);
        }

        // Sorting
        match ($sortBy) {
            'category'     => $query->orderBy('category')->orderBy('name'),
            'recent'       => $query->orderByDesc('created_at'),
            'updated'      => $query->orderByDesc('updated_at'),
            default        => $query->orderBy('name'),
        };

        $totalItems = $query->count();
        $items = $query->skip(($page - 1) * $limit)->take($limit)->get();

        // ── Categories (all active) ───────────────────────────────────────────
        $categories = InventoryCategory::where('archived', false)->orderBy('name')->get();

        // ── Response ─────────────────────────────────────────────────────────
        return response()->json([
            'categories' => $categories->map(fn ($c) => $this->transformCategory($c))->values(),
            'items'      => $items->map(fn ($i) => $this->transformItem($i))->values(),
            'total'      => $totalItems,
            'page'       => $page,
            'limit'      => $limit,
            'pages'      => $limit > 0 ? (int) ceil($totalItems / $limit) : 1,
            'summary'    => [
                'totalItems'          => InventoryItem::where('archived', false)->count(),
                'categoriesCount'     => $categories->count(),
                'filteredItemsCount'  => $totalItems,
            ],
            'meta'       => [
                'userRole'  => $request->user()?->role,
                'timestamp' => now()->toIso8601String(),
                'cached'    => false,
            ],
        ]);
    }

    // ==========================================
    // CATEGORIES CRUD
    // ==========================================

    public function getCategories(Request $request)
    {
        $query = InventoryCategory::query();

        if (!$request->boolean('includeArchived', false)) {
            $query->where('archived', false);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('description', 'like', $search);
            });
        }

        $categories = $query->orderBy('name')->get();

        return response()->json([
            'categories' => $categories->map(fn($c) => $this->transformCategory($c)),
            'total' => $categories->count()
        ]);
    }

    public function createCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'picture' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();

        $category = InventoryCategory::create([
            'name' => $request->name,
            'description' => $request->description,
            'picture' => $request->picture,
            'item_count' => 0,
            'archived' => false,
            'created_by' => $user->id,
        ]);

        $this->logActivity('category_created', 'category', $category->id, $category->name);

        return response()->json($this->transformCategory($category), 201);
    }

    public function updateCategory(Request $request, $id)
    {
        $category = InventoryCategory::find($id);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'picture' => 'nullable|string',
            'archived' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $oldData = $category->toArray();

        $category->fill($request->only(['name', 'description', 'picture', 'archived']));
        $category->updated_by = $user->id;
        $category->save();

        // Calculate changes
        $changes = [];
        foreach ($request->only(['name', 'description', 'picture', 'archived']) as $key => $value) {
            if (isset($oldData[$key]) && $oldData[$key] != $category->$key) {
                $changes[] = [
                    'field' => $key,
                    'oldValue' => $oldData[$key],
                    'newValue' => $category->$key
                ];
            }
        }

        $action = $request->has('archived') && $request->archived ? 'category_archived' : 'category_updated';
        $this->logActivity($action, 'category', $category->id, $category->name, $changes);

        return response()->json($this->transformCategory($category));
    }

    public function deleteCategory(Request $request, $id)
    {
        $category = InventoryCategory::find($id);
        if (!$category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $user = auth()->user();
        $reason = $request->input('reason', 'None provided');

        // Copy to deleted categories table
        DeletedInventoryCategory::create([
            'original_id' => $category->id,
            'category_data' => $category->toArray(),
            'deleted_by' => $user->id,
            'deleted_by_name' => $user->first_name . ' ' . $user->last_name,
            'deleted_by_role' => $user->role,
            'deleted_at' => Carbon::now(),
            'scheduled_deletion' => Carbon::now()->addDays(30),
            'reason' => $reason,
            'ip_address' => $request->ip(),
        ]);

        $this->logActivity('category_deleted', 'category', $category->id, $category->name, null, ['reason' => $reason]);

        // Soft delete items inside this category too
        $items = InventoryItem::where('category_id', $category->id)->get();
        foreach ($items as $item) {
            /** @var InventoryItem $item */
            DeletedInventoryItem::create([
                'original_id' => $item->id,
                'item_data' => $item->toArray(),
                'deleted_by' => $user->id,
                'deleted_by_name' => $user->first_name . ' ' . $user->last_name,
                'deleted_by_role' => $user->role,
                'deleted_at' => Carbon::now(),
                'scheduled_deletion' => Carbon::now()->addDays(30),
                'reason' => 'Parent category deleted: ' . $reason,
                'ip_address' => $request->ip(),
            ]);
            $this->logActivity('item_deleted', 'item', $item->id, $item->name, null, ['reason' => 'Parent category deleted: ' . $reason]);
            $item->delete(); // Soft delete using softDeletes
        }

        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category and its items deleted successfully']);
    }

    // ==========================================
    // ITEMS CRUD
    // ==========================================

    public function getItems(Request $request)
    {
        $query = InventoryItem::query();

        if (!$request->boolean('includeArchived', false)) {
            $query->where('archived', false);
        }

        if ($request->filled('category')) {
            $categoryVal = trim($request->category);
            $query->where('category', $categoryVal);
        }



        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('specification', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhere('id', 'like', $search);
            });
        }

        $total = $query->count();
        $limit = $request->integer('limit', 10);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $items = $query->orderBy('name')
                       ->skip(($page - 1) * $limit)
                       ->take($limit)
                       ->get();

        return response()->json([
            'items' => $items->map(fn($item) => $this->transformItem($item)),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ]);
    }

    public function getItemById($id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }
        return response()->json($this->transformItem($item));
    }

    public function createItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string',
            'categoryId' => 'nullable|integer',
            'specification' => 'nullable|string',
            'toolsOrEquipment' => 'nullable|string',
            'picture' => 'nullable|string',
            'quantity' => 'required|integer|min:0',
            'donations' => 'nullable|integer|min:0',
            'eomCount' => 'nullable|integer|min:0',
            'isrequired' => 'nullable|boolean',
            'maxQuantityPerRequest' => 'nullable|integer|min:1',
            'unitPrice' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();

        // Calculate stock
        $quantity = $request->integer('quantity', 0);
        $donations = $request->integer('donations', 0);
        $totalStock = $quantity + $donations;

        $item = InventoryItem::create([
            'name' => $request->name,
            'category' => $request->category,
            'category_id' => $request->categoryId,
            'specification' => $request->specification,
            'tools_or_equipment' => $request->toolsOrEquipment ?? '',
            'picture' => $request->picture,
            'quantity' => $quantity,
            'donations' => $donations,
            'eom_count' => $request->integer('eomCount', $totalStock),
            'is_required' => $request->boolean('isrequired', false),
            'max_quantity_per_request' => $request->maxQuantityPerRequest,
            'unit_price' => $request->unitPrice,
            'created_by' => $user->id,
        ]);

        // Increment category count
        if ($item->category_id) {
            InventoryCategory::where('id', $item->category_id)->increment('item_count');
        }

        $this->logActivity('item_created', 'item', $item->id, $item->name);

        return response()->json($this->transformItem($item), 201);
    }

    public function updateItem(Request $request, $id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string',
            'categoryId' => 'nullable|integer',
            'specification' => 'nullable|string',
            'toolsOrEquipment' => 'sometimes|nullable|string',
            'picture' => 'nullable|string',
            'quantity' => 'sometimes|required|integer|min:0',
            'donations' => 'nullable|integer|min:0',
            'eomCount' => 'nullable|integer|min:0',
            'isrequired' => 'sometimes|boolean',
            'maxQuantityPerRequest' => 'nullable|integer|min:1',
            'unitPrice' => 'nullable|numeric|min:0',
            'archived' => 'sometimes|boolean',
            'adjustmentType' => 'nullable|string|in:add,subtract',
            'adjustmentReason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $oldData = $item->toArray();

        // If category is changing, handle category counts
        $oldCategoryId = $item->category_id;
        $newCategoryId = $request->input('categoryId', $oldCategoryId);

        // Map inputs
        $updateData = [];
        if ($request->has('name')) $updateData['name'] = $request->name;
        if ($request->has('category')) $updateData['category'] = $request->category;
        if ($request->has('categoryId')) $updateData['category_id'] = $request->categoryId;
        if ($request->has('specification')) $updateData['specification'] = $request->specification;
        if ($request->has('toolsOrEquipment')) $updateData['tools_or_equipment'] = $request->toolsOrEquipment ?? '';
        if ($request->has('picture')) $updateData['picture'] = $request->picture;
        if ($request->has('quantity')) $updateData['quantity'] = $request->quantity;
        if ($request->has('donations')) $updateData['donations'] = $request->donations;
        if ($request->has('eomCount')) $updateData['eom_count'] = $request->eomCount;
        if ($request->has('isrequired')) $updateData['is_required'] = $request->isrequired;
        if ($request->has('maxQuantityPerRequest')) $updateData['max_quantity_per_request'] = $request->maxQuantityPerRequest;
        if ($request->has('unitPrice')) $updateData['unit_price'] = $request->unitPrice;
        if ($request->has('archived')) $updateData['archived'] = $request->archived;



        $item->update($updateData);
        $item->updated_by = $user->id;
        $item->save();

        // Adjust category counts if needed
        if ($oldCategoryId != $newCategoryId) {
            if ($oldCategoryId) {
                InventoryCategory::where('id', $oldCategoryId)->decrement('item_count');
            }
            if ($newCategoryId) {
                InventoryCategory::where('id', $newCategoryId)->increment('item_count');
            }
        }

        // Calculate changes
        $changes = [];
        foreach ($updateData as $key => $value) {
            $dbKey = ($key === 'is_required') ? 'is_required' : (($key === 'tools_or_equipment') ? 'tools_or_equipment' : (($key === 'max_quantity_per_request') ? 'max_quantity_per_request' : (($key === 'category_id') ? 'category_id' : (($key === 'unit_price') ? 'unit_price' : (($key === 'eom_count') ? 'eom_count' : $key)))));
            if (isset($oldData[$dbKey]) && $oldData[$dbKey] != $item->$dbKey) {
                $changes[] = [
                    'field' => $key,
                    'oldValue' => $oldData[$dbKey],
                    'newValue' => $item->$dbKey
                ];
            }
        }

        $action = $request->boolean('archived') ? 'item_archived' : 'item_updated';
        $metadata = [];
        if ($request->filled('adjustmentType') && $request->filled('adjustmentReason')) {
            $metadata['adjustment'] = [
                'type' => $request->adjustmentType,
                'reason' => $request->adjustmentReason
            ];
        }

        $this->logActivity($action, 'item', $item->id, $item->name, $changes, $metadata);

        return response()->json($this->transformItem($item));
    }

    public function deleteItem(Request $request, $id)
    {
        $item = InventoryItem::find($id);
        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        $user = auth()->user();
        $reason = $request->input('reason', 'None provided');

        // Copy to deleted inventory items
        DeletedInventoryItem::create([
            'original_id' => $item->id,
            'item_data' => $item->toArray(),
            'deleted_by' => $user->id,
            'deleted_by_name' => $user->first_name . ' ' . $user->last_name,
            'deleted_by_role' => $user->role,
            'deleted_at' => Carbon::now(),
            'scheduled_deletion' => Carbon::now()->addDays(30),
            'reason' => $reason,
            'ip_address' => $request->ip(),
        ]);

        // Decrement category count
        if ($item->category_id) {
            InventoryCategory::where('id', $item->category_id)->decrement('item_count');
        }

        $this->logActivity('item_deleted', 'item', $item->id, $item->name, null, ['reason' => $reason]);

        // Delete from Cloudinary if configured
        if ($item->picture) {
            StorageService::deleteByUrl($item->picture, 'inventory');
        }

        $item->delete(); // Soft delete using softDeletes trait

        return response()->json(['success' => true, 'message' => 'Item deleted successfully']);
    }

    public function bulkDeleteItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
            'reason' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $ids = $request->ids;
        $reason = $request->input('reason', 'Bulk deletion');
        $user = auth()->user();

        $items = InventoryItem::whereIn('id', $ids)->get();
        $deletedCount = 0;

        foreach ($items as $item) {
            // Copy to deleted inventory items
            DeletedInventoryItem::create([
                'original_id' => $item->id,
                'item_data' => $item->toArray(),
                'deleted_by' => $user->id,
                'deleted_by_name' => $user->first_name . ' ' . $user->last_name,
                'deleted_by_role' => $user->role,
                'deleted_at' => Carbon::now(),
                'scheduled_deletion' => Carbon::now()->addDays(30),
                'reason' => $reason,
                'ip_address' => $request->ip(),
            ]);

            // Decrement category count
            if ($item->category_id) {
                InventoryCategory::where('id', $item->category_id)->decrement('item_count');
            }

            // Log activity
            $this->logActivity('item_deleted', 'item', $item->id, $item->name, null, ['reason' => $reason]);

            // Delete from Cloudinary if configured
            if ($item->picture) {
                StorageService::deleteByUrl($item->picture, 'inventory');
            }

            // Soft delete
            $item->delete();
            $deletedCount++;
        }

        return response()->json([
            'success' => true,
            'message' => "Successfully deleted {$deletedCount} items"
        ]);
    }

    public function bulkCreateItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.name' => 'required|string|max:255',
            'items.*.category' => 'required|string',
            'items.*.categoryId' => 'nullable|integer',
            'items.*.specification' => 'nullable|string',
            'items.*.toolsOrEquipment' => 'nullable|string',
            'items.*.picture' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $createdCount = 0;
        $failedCount = 0;
        $failures = [];

        foreach ($request->items as $index => $itemData) {
            try {
                $qty = (int) ($itemData['quantity'] ?? 0);

                $item = InventoryItem::create([
                    'name' => $itemData['name'],
                    'category' => $itemData['category'],
                    'category_id' => $itemData['categoryId'] ?? null,
                    'specification' => $itemData['specification'] ?? '',
                    'tools_or_equipment' => $itemData['toolsOrEquipment'] ?? '',
                    'picture' => $itemData['picture'] ?? null,
                    'quantity' => $qty,
                    'donations' => 0,
                    'eom_count' => $qty,
                    'created_by' => $user->id,
                ]);

                if ($item->category_id) {
                    InventoryCategory::where('id', $item->category_id)->increment('item_count');
                }

                $this->logActivity('item_created', 'item', $item->id, $item->name, null, ['bulk' => true]);
                $createdCount++;
            } catch (\Exception $e) {
                $failedCount++;
                $failures[] = [
                    'index' => $index,
                    'name' => $itemData['name'] ?? 'Unknown',
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'createdCount' => $createdCount,
            'failedCount' => $failedCount,
            'failures' => $failures
        ], 200);
    }

    // ==========================================
    // REQUIRED ITEMS
    // ==========================================

    public function getRequiredItems()
    {
        $items = InventoryItem::where('is_required', true)->where('archived', false)->get();
        return response()->json([
            'items' => $items->map(fn($item) => $this->transformItem($item)),
            'total' => $items->count(),
            'meta' => [
                'cached' => false,
                'timestamp' => Carbon::now()->toIso8601String()
            ]
        ]);
    }

    public function bulkUpdateRequired(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'itemIds' => 'required|array',
            'itemIds.*' => 'required|integer',
            'isrequired' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $itemIds = $request->itemIds;
        $isRequired = $request->isrequired;

        InventoryItem::whereIn('id', $itemIds)->update(['is_required' => $isRequired]);

        $updatedItems = InventoryItem::whereIn('id', $itemIds)->get();

        foreach ($updatedItems as $item) {
            $this->logActivity('item_updated', 'item', $item->id, $item->name, [
                [
                    'field' => 'isrequired',
                    'oldValue' => !$isRequired,
                    'newValue' => $isRequired
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk required status updated successfully',
            'items' => $updatedItems->map(fn($item) => $this->transformItem($item)),
            'updatedCount' => $updatedItems->count()
        ]);
    }

    // ==========================================
    // DELETED / RESTORE / ARCHIVED ITEMS
    // ==========================================

    public function getArchivedItems(Request $request)
    {
        $query = InventoryItem::where('archived', true);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where('name', 'like', $search);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $total = $query->count();
        $limit = $request->integer('limit', 10);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $items = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return response()->json([
            'items' => $items->map(fn($item) => $this->transformItem($item)),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ]);
    }

    public function restoreArchivedItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'itemId' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $item = InventoryItem::find($request->itemId);
        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        $item->archived = false;
        $item->save();

        $this->logActivity('item_restored', 'item', $item->id, $item->name, [
            [
                'field' => 'archived',
                'oldValue' => true,
                'newValue' => false
            ]
        ]);

        return response()->json(['success' => true, 'message' => 'Item restored successfully']);
    }

    public function getDeletedItems(Request $request)
    {
        // Get deleted items
        $deletedItems = DeletedInventoryItem::query();
        $deletedCategories = DeletedInventoryCategory::query();

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $deletedItems->where('item_data->name', 'like', $search);
            $deletedCategories->where('category_data->name', 'like', $search);
        }

        $itemsData = $deletedItems->get()->map(fn($d) => [
            'id' => (string) $d->id,
            'originalId' => (string) $d->original_id,
            'type' => 'item',
            'itemData' => $d->item_data,
            'deletedBy' => (string) $d->deleted_by,
            'deletedByName' => $d->deleted_by_name,
            'deletedByRole' => $d->deleted_by_role,
            'deletedAt' => $d->deleted_at->toIso8601String(),
            'scheduledDeletion' => $d->scheduled_deletion->toIso8601String(),
            'daysRemaining' => max(0, Carbon::now()->diffInDays($d->scheduled_deletion, false)),
            'reason' => $d->reason
        ]);

        $categoriesData = $deletedCategories->get()->map(fn($d) => [
            'id' => (string) $d->id,
            'originalId' => (string) $d->original_id,
            'type' => 'category',
            'categoryData' => $d->category_data,
            'deletedBy' => (string) $d->deleted_by,
            'deletedByName' => $d->deleted_by_name,
            'deletedByRole' => $d->deleted_by_role,
            'deletedAt' => $d->deleted_at->toIso8601String(),
            'scheduledDeletion' => $d->scheduled_deletion->toIso8601String(),
            'daysRemaining' => max(0, Carbon::now()->diffInDays($d->scheduled_deletion, false)),
            'reason' => $d->reason
        ]);

        $combined = $itemsData->concat($categoriesData)->sortByDesc('deletedAt')->values();

        $total = $combined->count();
        $limit = $request->integer('limit', 10);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $paged = $combined->slice(($page - 1) * $limit, $limit)->values();

        return response()->json([
            'items' => $paged,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ]);
    }

    public function restoreDeletedItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'deletedId' => 'required',
            'type' => 'required|string|in:item,category'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $id = $request->deletedId;
        $type = $request->type;

        if ($type === 'item') {
            $deletedDoc = DeletedInventoryItem::find($id);
            if (!$deletedDoc) {
                return response()->json(['error' => 'Deleted record not found'], 404);
            }

            // Restore from soft delete
            $restored = InventoryItem::withTrashed()->find($deletedDoc->original_id);
            if ($restored) {
                $restored->restore();
            } else {
                // Recreate if not existing in soft deletes
                $data = $deletedDoc->item_data;
                unset($data['id']);
                unset($data['deleted_at']);
                $restored = InventoryItem::create($data);
            }

            // Category counts
            if ($restored->category_id) {
                InventoryCategory::where('id', $restored->category_id)->increment('item_count');
            }

            $this->logActivity('item_restored', 'item', $restored->id, $restored->name);
            $deletedDoc->delete();

            return response()->json(['success' => true, 'message' => 'Item restored successfully']);
        } else {
            $deletedDoc = DeletedInventoryCategory::find($id);
            if (!$deletedDoc) {
                return response()->json(['error' => 'Deleted record not found'], 404);
            }

            $restored = InventoryCategory::find($deletedDoc->original_id);
            if (!$restored) {
                $data = $deletedDoc->category_data;
                unset($data['id']);
                $restored = InventoryCategory::create($data);
            }

            $this->logActivity('category_restored', 'category', $restored->id, $restored->name);
            $deletedDoc->delete();

            return response()->json(['success' => true, 'message' => 'Category restored successfully']);
        }
    }

    public function permanentlyDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'deletedId' => 'required',
            'type' => 'required|string|in:item,category'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $id = $request->deletedId;
        $type = $request->type;

        if ($type === 'item') {
            $deletedDoc = DeletedInventoryItem::find($id);
            if (!$deletedDoc) {
                return response()->json(['error' => 'Deleted record not found'], 404);
            }

            $item = InventoryItem::withTrashed()->find($deletedDoc->original_id);
            if ($item) {
                if ($item->picture) {
                    StorageService::deleteByUrl($item->picture, 'inventory');
                }
                $item->forceDelete();
            }

            $deletedDoc->delete();
            return response()->json(['success' => true, 'message' => 'Item permanently deleted']);
        } else {
            $deletedDoc = DeletedInventoryCategory::find($id);
            if (!$deletedDoc) {
                return response()->json(['error' => 'Deleted record not found'], 404);
            }

            $category = InventoryCategory::find($deletedDoc->original_id);
            if ($category) {
                $category->delete();
            }

            $deletedDoc->delete();
            return response()->json(['success' => true, 'message' => 'Category permanently deleted']);
        }
    }

    // ==========================================
    // IMAGE UPLOAD
    // ==========================================

    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $file = $request->file('file');
        
        try {
            $result = StorageService::upload($file, 'inventory');
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Upload failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ==========================================
    // AUDIT LOGS
    // ==========================================

    public function getActivityLogs(Request $request)
    {
        $query = InventoryActivityLog::query();

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('entityType')) {
            $query->where('entity_type', $request->entityType);
        }

        if ($request->filled('entityId')) {
            $query->where('entity_id', $request->entityId);
        }

        if ($request->filled('userId')) {
            $query->where('user_id', $request->userId);
        }

        if ($request->filled('startDate')) {
            $query->where('timestamp', '>=', Carbon::parse($request->startDate));
        }

        if ($request->filled('endDate')) {
            $query->where('timestamp', '<=', Carbon::parse($request->endDate));
        }

        $total = $query->count();
        $limit = $request->integer('limit', 15);
        $page = $request->integer('page', 1);
        $pages = max(1, ceil($total / $limit));

        $logs = $query->orderBy('timestamp', 'desc')
                      ->skip(($page - 1) * $limit)
                      ->take($limit)
                      ->get();

        return response()->json([
            'activityLogs' => $logs->map(fn($h) => [
                'id' => (string) $h->id,
                'action' => $h->action,
                'entityType' => $h->entity_type,
                'entityId' => (string) $h->entity_id,
                'entityName' => $h->entity_name,
                'userId' => (string) $h->user_id,
                'userName' => $h->user_name,
                'userRole' => $h->user_role,
                'changes' => $h->changes,
                'metadata' => $h->metadata,
                'ipAddress' => $h->ip_address,
                'timestamp' => $h->timestamp->toIso8601String()
            ]),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ]);
    }

    // ==========================================
    // SSE STREAM
    // ==========================================

    public function stream()
    {
        return new StreamedResponse(function () {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo "retry: 15000\n";
            echo "event: connected\n";
            echo "data: {}\n\n";
            flush();

            $hasMultipleWorkers = function_exists('pcntl_fork') && getenv('PHP_CLI_SERVER_WORKERS') && intval(getenv('PHP_CLI_SERVER_WORKERS')) > 1;
            if (php_sapi_name() !== 'cli-server' || $hasMultipleWorkers) {
                // Simple keep alive comments
                $start = time();
                while (time() - $start < 30) {
                    echo ": keepalive\n\n";
                    flush();
                    sleep(5);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ==========================================
    // EXCEL EXPORTS (CSV generator that Excel opens cleanly)
    // ==========================================

    public function export(Request $request)
    {
        $sheets = explode(',', $request->input('sheets', 'inventory'));
        $columns = explode(',', $request->input('columns', 'id,name,quantity,category'));
        
        $categoriesFilter = $request->filled('categories') ? explode(',', $request->categories) : [];
        $specificationsFilter = $request->filled('specifications') ? explode(',', $request->specifications) : [];
        $toolsFilter = $request->filled('tools') ? explode(',', $request->tools) : [];

        $query = InventoryItem::where('archived', false);
        if (!empty($categoriesFilter)) {
            $query->whereIn('category', $categoriesFilter);
        }

        $items = $query->get();

        // Create CSV output
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inventory-export.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $callback = function() use ($items, $columns) {
            $file = fopen('php://output', 'w');

            // Explicit human-readable labels – must match the import parser's known headers
            $columnLabels = [
                'id'                    => 'ID',
                'name'                  => 'Name',
                'category'              => 'Category',
                'specification'         => 'Specification',
                'toolsOrEquipment'      => 'Tools or Equipment',
                'quantity'              => 'Current Count',
                'donations'             => 'Donations',
                'eomCount'              => 'EOM Count',
                'unitPrice'             => 'Unit Price',
                'isrequired'            => 'Required',
                'maxQuantityPerRequest' => 'Max Quantity Per Request',
                'image'                 => 'Picture',
                'picture'               => 'Picture',
            ];
            $headerTitles = array_map(fn($col) => $columnLabels[$col] ?? ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $col)), $columns);
            fputcsv($file, $headerTitles);

            foreach ($items as $item) {
                $row = [];
                foreach ($columns as $column) {
                    switch ($column) {
                        case 'id': $row[] = $item->id; break;
                        case 'name': $row[] = $item->name; break;
                        case 'category': $row[] = $item->category; break;
                        case 'specification': $row[] = $item->specification; break;
                        case 'toolsOrEquipment': $row[] = $item->tools_or_equipment; break;
                        case 'quantity': $row[] = $item->quantity; break;
                        case 'donations': $row[] = $item->donations; break;
                        case 'eomCount': $row[] = $item->eom_count; break;
                        case 'unitPrice': $row[] = $item->unit_price; break;
                        case 'isrequired': $row[] = $item->is_required ? 'Yes' : 'No'; break;
                        case 'maxQuantityPerRequest': $row[] = $item->max_quantity_per_request; break;
                        case 'image':
                        case 'picture': $row[] = $item->picture ?? ''; break;
                        default: $row[] = '';
                    }
                }
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function getAllBorrowers(Request $request)
    {
        $borrowRequestItems = \App\Models\BorrowRequestItem::whereHas('borrowRequest', function($q) {
                $q->whereIn('status', ['borrowed', 'pending_return', 'pending_appeal']);
            })
            ->with(['borrowRequest.student', 'borrowRequest.instructor', 'borrowRequest.classCode', 'item'])
            ->get();
            
        $borrowers = $borrowRequestItems->map(function($bri) {
            $br = $bri->borrowRequest;
            return [
                'borrow_request_id' => $br->id,
                'item_id' => $bri->item_id,
                'item_name' => $bri->name ?? ($bri->item ? $bri->item->name : 'Unknown Item'),
                'item_specification' => $bri->item ? $bri->item->specification : '',
                'item_category' => $bri->category ?? ($bri->item ? $bri->item->category : ''),
                'item_picture' => $bri->picture ?? ($bri->item ? $bri->item->picture : null),
                'quantity' => $bri->quantity,
                'due_date' => $bri->due_date ? $bri->due_date->toIso8601String() : ($br->return_date ? $br->return_date->toIso8601String() : null),
                'borrow_date' => $br->borrow_date ? $br->borrow_date->toIso8601String() : null,
                'status' => $br->status,
                'student' => $br->student ? [
                    'id' => $br->student->id,
                    'name' => trim($br->student->first_name . ' ' . $br->student->last_name),
                    'email' => $br->student->email,
                ] : null,
                'instructor' => $br->instructor ? [
                    'id' => $br->instructor->id,
                    'name' => trim($br->instructor->first_name . ' ' . $br->instructor->last_name),
                    'email' => $br->instructor->email,
                ] : null,
                'class_code' => $br->classCode ? [
                    'id' => $br->classCode->id,
                    'code' => $br->classCode->code,
                    'name' => $br->classCode->name,
                ] : null,
            ];
        });

        return response()->json([
            'borrowers' => $borrowers
        ]);
    }
}
