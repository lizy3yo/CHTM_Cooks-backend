<?php

namespace App\Services;

use App\Models\BorrowRequestItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-date stock availability.
 *
 * Inventory quantity alone cannot answer "can this be borrowed on Thursday?" —
 * it only says what is sitting on the shelf right now. A unit picked up today
 * and returned today is free again on Thursday, and a unit merely booked for
 * Thursday is not, even though it is still on the shelf.
 *
 * Bookings are single-day (borrow_date and return_date share a calendar day),
 * so a request only ties up stock on its own borrow_date.
 */
class AvailabilityService
{
    /**
     * Statuses that actually hold stock for their booked day.
     *
     * 'pending_instructor' is deliberately absent: an unapproved request
     * reserves nothing, so availability is settled at approval time instead.
     */
    public const HOLDING_STATUSES = [
        'approved_instructor',
        'ready_for_pickup',
        'borrowed',
        'pending_return',
    ];

    /** Statuses where the units have physically left the storeroom. */
    public const OUT_STATUSES = [
        'borrowed',
        'pending_return',
    ];

    /**
     * Units of each item already spoken for on a given calendar day.
     *
     * @param  int[]  $itemIds
     * @param  int|null  $exceptRequestId  Ignore this request's own lines, so a
     *                                     request being approved does not count
     *                                     against itself.
     * @return array<int,int>  itemId => committed quantity
     */
    public static function committedOn(array $itemIds, string $date, ?int $exceptRequestId = null): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return BorrowRequestItem::query()
            ->whereIn('item_id', $itemIds)
            ->when($exceptRequestId, fn ($q) => $q->where('borrow_request_id', '!=', $exceptRequestId))
            ->whereHas('borrowRequest', function ($q) use ($date) {
                $q->whereDate('borrow_date', $date)
                    ->whereIn('status', self::HOLDING_STATUSES);
            })
            ->selectRaw('item_id, SUM(quantity) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Units that are out past their booked day and not yet back.
     *
     * These still count as owned, but nobody can promise they return in time,
     * so they surface as a warning rather than reducing the free count.
     *
     * @param  int[]  $itemIds
     * @return array<int,int>  itemId => at-risk quantity
     */
    public static function atRisk(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return BorrowRequestItem::query()
            ->whereIn('item_id', $itemIds)
            ->whereHas('borrowRequest', function ($q) {
                $q->whereIn('status', self::OUT_STATUSES)
                    ->whereDate('borrow_date', '<', Carbon::today());
            })
            ->selectRaw('item_id, SUM(quantity) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Units currently outside the storeroom, per item. Stock is deducted at
     * pickup, so these must be added back to learn how many units exist.
     *
     * @param  int[]  $itemIds
     * @return array<int,int>  itemId => quantity currently out
     */
    public static function currentlyOut(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        return BorrowRequestItem::query()
            ->whereIn('item_id', $itemIds)
            ->whereHas('borrowRequest', fn ($q) => $q->whereIn('status', self::OUT_STATUSES))
            ->selectRaw('item_id, SUM(quantity) as total')
            ->groupBy('item_id')
            ->pluck('total', 'item_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }

    /**
     * Availability for a set of items across one or more dates.
     *
     * @param  Collection  $items  InventoryItem models (quantity + donations read from these)
     * @param  string[]  $dates  Calendar days as Y-m-d
     * @return array<int,array<string,array{owned:int,committed:int,free:int,delayed:int}>>
     *         itemId => date => figures
     */
    public static function forDates(Collection $items, array $dates, ?int $exceptRequestId = null): array
    {
        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (empty($itemIds) || empty($dates)) {
            return [];
        }

        $out = self::currentlyOut($itemIds);
        $delayed = self::atRisk($itemIds);

        $committedByDate = [];
        foreach ($dates as $date) {
            $committedByDate[$date] = self::committedOn($itemIds, $date, $exceptRequestId);
        }

        $result = [];

        foreach ($items as $item) {
            $id = (int) $item->id;
            $owned = (int) ($item->quantity ?? 0)
                + (int) ($item->donations ?? 0)
                + ($out[$id] ?? 0);

            foreach ($dates as $date) {
                $committed = $committedByDate[$date][$id] ?? 0;

                $result[$id][$date] = [
                    'owned' => $owned,
                    'committed' => $committed,
                    'free' => max(0, $owned - $committed),
                    'delayed' => $delayed[$id] ?? 0,
                ];
            }
        }

        return $result;
    }
}
