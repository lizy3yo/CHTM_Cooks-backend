<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;

class CartController extends Controller
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the authenticated student's raw cart rows.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getCartRows(int $userId)
    {
        return DB::table('student_carts')
            ->where('user_id', $userId)
            ->orderBy('added_at', 'asc')
            ->get();
    }

    /**
     * Shape a single DB row into the API response format expected by the
     * frontend CartItemResponse interface.
     */
    private function transformItem(object $row): array
    {
        return [
            'itemId'      => (string) $row->item_id,
            'name'        => $row->name,
            'quantity'    => (int) $row->quantity,
            'maxQuantity' => (int) $row->max_quantity,
            'categoryId'  => $row->category_id ? (string) $row->category_id : null,
            'picture'     => $row->picture,
            'addedAt'     => Carbon::parse($row->added_at)->toIso8601String(),
            'updatedAt'   => Carbon::parse($row->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Build the CartResponse envelope used by every endpoint.
     */
    private function buildCartResponse(\Illuminate\Support\Collection $rows): array
    {
        $latestUpdate = $rows->max('updated_at') ?? now();

        return [
            'items'     => $rows->map(fn(object $r) => $this->transformItem($r))->values()->all(),
            'updatedAt' => Carbon::parse($latestUpdate)->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /api/cart
     *
     * Return the authenticated student's current cart.
     */
    public function getCart(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $rows = $this->getCartRows($user->id);

        return response()->json($this->buildCartResponse($rows));
    }

    /**
     * POST /api/cart
     *
     * Add an item to the cart or increment its quantity if it already exists.
     * Caps quantity at maxQuantity.
     *
     * With `ifAbsent`, an item already in the cart is left exactly as it is, so
     * the call is idempotent — sending it twice never raises the quantity.
     */
    public function addItem(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'itemId'      => 'required|string',
            'name'        => 'required|string|max:255',
            'quantity'    => 'sometimes|integer|min:1',
            'maxQuantity' => 'required|integer|min:1',
            'categoryId'  => 'nullable|string',
            'picture'     => 'nullable|string',
            'ifAbsent'    => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(
                ['error' => 'Validation failed', 'details' => $validator->errors()],
                400
            );
        }

        $user       = Auth::user();
        $requestQty = (int) ($request->input('quantity', 1));
        $maxQty     = (int) $request->maxQuantity;
        $result     = 'added';

        $existing = DB::table('student_carts')
            ->where('user_id', $user->id)
            ->where('item_id', $request->itemId)
            ->first();

        if ($existing && $request->boolean('ifAbsent')) {
            $result = 'unchanged';
        } elseif ($existing) {
            $newQty = $existing->quantity + $requestQty;

            if ($newQty >= $maxQty) {
                $newQty = $maxQty;
                $result = 'capped';
            } else {
                $result = 'incremented';
            }

            DB::table('student_carts')
                ->where('id', $existing->id)
                ->update([
                    'quantity'    => $newQty,
                    'max_quantity' => $maxQty,
                    'updated_at'  => now(),
                ]);
        } else {
            $clampedQty = min($requestQty, $maxQty);

            DB::table('student_carts')->insert([
                'user_id'     => $user->id,
                'item_id'     => $request->itemId,
                'name'        => $request->name,
                'quantity'    => $clampedQty,
                'max_quantity' => $maxQty,
                'category_id' => $request->categoryId,
                'picture'     => $request->picture,
                'added_at'    => now(),
                'updated_at'  => now(),
            ]);
        }

        $rows     = $this->getCartRows($user->id);
        $response = $this->buildCartResponse($rows);
        $response['result'] = $result;

        return response()->json($response, 200);
    }

    /**
     * POST /api/cart/batch
     *
     * Add several items in one call, e.g. a "required for lab" set. Only items
     * missing from the cart are inserted (quantity 1); items already there keep
     * the quantity the student chose. Idempotent, so a retry or a second click
     * changes nothing.
     */
    public function addItems(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items'               => 'required|array|min:1|max:100',
            'items.*.itemId'      => 'required|string',
            'items.*.name'        => 'required|string|max:255',
            'items.*.maxQuantity' => 'required|integer|min:1',
            'items.*.categoryId'  => 'nullable|string',
            'items.*.picture'     => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(
                ['error' => 'Validation failed', 'details' => $validator->errors()],
                400
            );
        }

        $user = Auth::user();

        $existingIds = DB::table('student_carts')
            ->where('user_id', $user->id)
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $rows = collect($request->input('items'))
            ->unique('itemId')
            ->reject(fn (array $item) => in_array((string) $item['itemId'], $existingIds, true))
            ->map(fn (array $item) => [
                'user_id'      => $user->id,
                'item_id'      => $item['itemId'],
                'name'         => $item['name'],
                'quantity'     => 1,
                'max_quantity' => (int) $item['maxQuantity'],
                'category_id'  => $item['categoryId'] ?? null,
                'picture'      => $item['picture'] ?? null,
                'added_at'     => now(),
                'updated_at'   => now(),
            ])
            ->values()
            ->all();

        // Two pages can sync at the same moment; the (user, item) unique key
        // makes the slower one a no-op instead of a duplicate-key error.
        $added = empty($rows) ? 0 : DB::table('student_carts')->insertOrIgnore($rows);

        $response = $this->buildCartResponse($this->getCartRows($user->id));
        $response['added'] = $added;

        return response()->json($response, 200);
    }

    /**
     * PATCH /api/cart
     *
     * Update the quantity of a specific item in the cart.
     * Clamps value to [1, maxQuantity].
     */
    public function updateQuantity(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'itemId'   => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(
                ['error' => 'Validation failed', 'details' => $validator->errors()],
                400
            );
        }

        $user = Auth::user();

        $existing = DB::table('student_carts')
            ->where('user_id', $user->id)
            ->where('item_id', $request->itemId)
            ->first();

        if (!$existing) {
            return response()->json(['error' => 'Item not found in cart'], 404);
        }

        $clampedQty = max(1, min((int) $request->quantity, (int) $existing->max_quantity));

        DB::table('student_carts')
            ->where('id', $existing->id)
            ->update([
                'quantity'   => $clampedQty,
                'updated_at' => now(),
            ]);

        $rows = $this->getCartRows($user->id);

        return response()->json($this->buildCartResponse($rows));
    }

    /**
     * DELETE /api/cart
     *
     * Remove a single item when ?itemId= is supplied, or clear the entire
     * cart when the query parameter is absent.
     *
     * A required item cannot be removed on its own — every request carries
     * it. Clearing the whole cart is still allowed (after submitting, or a
     * form reset, which puts the required items straight back).
     */
    public function deleteFromCart(Request $request): \Illuminate\Http\JsonResponse
    {
        $user   = Auth::user();
        $itemId = $request->query('itemId');

        if ($itemId) {
            $isRequired = InventoryItem::where('id', $itemId)
                ->where('archived', false)
                ->where('is_required', true)
                ->exists();

            if ($isRequired) {
                return response()->json(
                    ['error' => 'Required items cannot be removed from your request list.'],
                    422
                );
            }

            // Remove specific item
            $deleted = DB::table('student_carts')
                ->where('user_id', $user->id)
                ->where('item_id', $itemId)
                ->delete();

            if (!$deleted) {
                return response()->json(['error' => 'Item not found in cart'], 404);
            }
        } else {
            // Clear entire cart
            DB::table('student_carts')
                ->where('user_id', $user->id)
                ->delete();
        }

        $rows = $this->getCartRows($user->id);

        return response()->json($this->buildCartResponse($rows));
    }

    /**
     * GET /api/cart/stream
     *
     * Server-Sent Events stream for real-time cart updates.
     * The frontend reconnects automatically; we simply keep the connection
     * alive with periodic heartbeats.
     */
    public function stream(): StreamedResponse
    {
        return new StreamedResponse(function () {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $timestamp = Carbon::now()->toIso8601String();

            echo "retry: 15000\n";
            echo "event: connected\n";
            echo 'data: ' . json_encode(['message' => 'Cart stream connected', 'timestamp' => $timestamp]) . "\n\n";
            flush();

            $hasMultipleWorkers = function_exists('pcntl_fork') && getenv('PHP_CLI_SERVER_WORKERS') && intval(getenv('PHP_CLI_SERVER_WORKERS')) > 1;
            if (php_sapi_name() !== 'cli-server' || $hasMultipleWorkers) {
                $start = time();
                while (time() - $start < 30) {
                    echo "event: heartbeat\n";
                    echo 'data: ' . json_encode(['timestamp' => Carbon::now()->toIso8601String()]) . "\n\n";
                    flush();
                    sleep(10);
                }
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
