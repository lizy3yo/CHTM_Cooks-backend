<?php

namespace App\Services;

use App\Models\BorrowRequest;
use App\Models\BorrowRequestItem;
use App\Models\Donation;
use App\Models\InventoryActivityLog;
use App\Models\InventoryItem;
use App\Models\ReplacementObligation;
use App\Models\User;
use App\Models\WalkInTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory variance: what the storeroom has against what it should have.
 *
 * Every item has a starting count for the month (`inventory_items.eom_count`).
 *
 *   Over  — stock gained since the month began, each entry with its source:
 *           a donation, units a borrower gave beyond what they owed, or a
 *           custodian's stock adjustment.
 *   Under — units that should be on the shelf and are not, each with the
 *           person responsible: still out on loan, or damaged / missing and
 *           awaiting replacement.
 *
 * At month end the books are closed: whatever is on hand becomes the new
 * starting count, so Over begins empty again, while Under keeps running until
 * every unit is back or replaced.
 */
class InventoryVariance
{
    private static ?bool $available = null;

    /** False until the migration has run; see BorrowRequestEvent::available(). */
    public static function available(): bool
    {
        return static::$available ??= Schema::hasTable('inventory_period_closings');
    }

    // ── Month-end close ─────────────────────────────────────────────────────

    /**
     * Close last month if it has not been closed yet. Safe to call on every
     * read: it does nothing once the closing exists.
     *
     * The very first closing is a restart. The starting counts kept until then
     * were maintained by hand and no longer described the shelf, so each one
     * is reset to what the item should have today; the old value is kept on
     * the closing row.
     */
    public static function closeDue(?User $by = null): bool
    {
        if (!static::available()) {
            return false;
        }

        $period = Carbon::now()->startOfMonth()->subMonth()->format('Y-m');

        if (DB::table('inventory_period_closings')->where('period', $period)->exists()) {
            return false;
        }

        try {
            return DB::transaction(function () use ($period, $by) {
                $isRestart = !DB::table('inventory_period_closings')->exists();
                $now = Carbon::now();

                // The unique index on `period` makes a concurrent close fail
                // here rather than close the month twice.
                $closingId = DB::table('inventory_period_closings')->insertGetId([
                    'period' => $period,
                    'closed_at' => $now,
                    'closed_by' => $by?->id,
                    'is_restart' => $isRestart,
                ]);

                $outstanding = self::outstanding();
                $totals = ['items' => 0, 'opening' => 0, 'closing' => 0, 'over' => 0, 'off' => 0, 'carried' => 0];
                $rows = [];

                foreach (InventoryItem::where('archived', false)->get() as $item) {
                    $onHand = (int) $item->quantity + (int) $item->donations;
                    $out = $outstanding[$item->id]['out'] ?? 0;
                    $owed = $outstanding[$item->id]['owed'] ?? 0;
                    $before = (int) $item->eom_count;

                    // What the school owns: on the shelf, with borrowers, or owed back.
                    $after = $onHand + $out + $owed;

                    $rows[] = [
                        'closing_id' => $closingId,
                        'item_id' => $item->id,
                        'item_name' => $item->name,
                        'category' => $item->category,
                        'baseline_before' => $before,
                        'on_hand' => $onHand,
                        'out_on_loan' => $out,
                        'owed' => $owed,
                        'over_absorbed' => max(0, $after - $before),
                        'written_off' => max(0, $before - $after),
                        'baseline_after' => $after,
                    ];

                    $totals['items']++;
                    $totals['opening'] += $before;
                    $totals['closing'] += $after;
                    $totals['over'] += max(0, $after - $before);
                    $totals['off'] += max(0, $before - $after);
                    $totals['carried'] += $out + $owed;

                    if ($before !== $after) {
                        // Query builder: a closing is bookkeeping, not an edit
                        // to the item, so updated_at and the activity log stay put.
                        DB::table('inventory_items')->where('id', $item->id)->update(['eom_count' => $after]);
                    }
                }

                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('inventory_period_closing_items')->insert($chunk);
                }

                DB::table('inventory_period_closings')->where('id', $closingId)->update([
                    'items_count' => $totals['items'],
                    'opening_total' => $totals['opening'],
                    'closing_total' => $totals['closing'],
                    'over_absorbed' => $totals['over'],
                    'written_off' => $totals['off'],
                    'under_carried' => $totals['carried'],
                ]);

                return true;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return false;
        }
    }

    /**
     * Units per item that the school owns but that are not on the shelf.
     *
     * @return array<int, array{out:int, owed:int}>
     */
    private static function outstanding(): array
    {
        $result = [];

        $out = BorrowRequestItem::query()
            ->whereIn('borrow_request_id', BorrowRequest::whereIn('status', BorrowRequest::OUT_STATUSES)->select('id'))
            ->selectRaw('item_id, SUM(quantity) as units')
            ->groupBy('item_id')
            ->pluck('units', 'item_id');

        foreach ($out as $itemId => $units) {
            $result[(int) $itemId]['out'] = (int) $units;
        }

        $owed = ReplacementObligation::where('status', 'pending')
            ->selectRaw('item_id, SUM(amount - amount_paid) as units')
            ->groupBy('item_id')
            ->pluck('units', 'item_id');

        foreach ($owed as $itemId => $units) {
            $result[(int) $itemId]['owed'] = max(0, (int) $units);
        }

        return $result;
    }

    // ── Report ──────────────────────────────────────────────────────────────

    /**
     * @param string $month yyyy-mm; the current month shows open shortages live.
     */
    public static function report(string $month): array
    {
        $now = Carbon::now();
        $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $isCurrent = $start->isSameMonth($now);

        $items = InventoryItem::all()->keyBy('id');
        $category = fn ($itemId, $fallback = null) => $items[$itemId]->category ?? $fallback;

        $over = self::overEntries($start, $end, $category);
        $adjustmentsDown = self::adjustmentEntries($start, $end, $category, false);
        $under = $isCurrent
            ? array_merge(self::openShortages($now, $category), $adjustmentsDown)
            : $adjustmentsDown;

        usort($over, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        usort($under, fn ($a, $b) => ($b['daysOutstanding'] ?? 0) <=> ($a['daysOutstanding'] ?? 0));

        // The closing that opened this month, and the one that ended it.
        $opening = self::available()
            ? DB::table('inventory_period_closings')->where('period', $start->copy()->subMonth()->format('Y-m'))->first()
            : null;
        $closing = self::available()
            ? DB::table('inventory_period_closings')->where('period', $month)->first()
            : null;

        $stillOpen = array_values(array_filter($under, fn ($e) => $e['open']));

        return [
            'month' => $month,
            'monthLabel' => $start->format('F Y'),
            'isCurrentMonth' => $isCurrent,
            'summary' => [
                'startingCount' => $opening ? (int) $opening->closing_total : null,
                'startedAt' => $opening ? Carbon::parse($opening->closed_at)->toIso8601String() : null,
                'isRestart' => $opening ? (bool) $opening->is_restart : false,
                'overUnits' => array_sum(array_column($over, 'quantity')),
                'overEntries' => count($over),
                'underUnits' => array_sum(array_column($under, 'quantity')),
                'underOpenUnits' => array_sum(array_column($stillOpen, 'quantity')),
                'underEntries' => count($under),
            ],
            'over' => $over,
            'under' => $under,
            'unexplained' => $isCurrent ? self::unexplained($items, $opening) : [],
            'closing' => $closing ? [
                'closedAt' => Carbon::parse($closing->closed_at)->toIso8601String(),
                'overAbsorbed' => (int) $closing->over_absorbed,
                'writtenOff' => (int) $closing->written_off,
                'underCarried' => (int) $closing->under_carried,
                'closingTotal' => (int) $closing->closing_total,
            ] : null,
        ];
    }

    private static function requestCode($id): string
    {
        return 'REQ-' . strtoupper(substr((string) $id, -6));
    }

    private static function fullName($user, string $fallback = 'Unknown'): string
    {
        return $user ? trim($user->first_name . ' ' . $user->last_name) : $fallback;
    }

    /** Stock gained in the period, each entry with where it came from. */
    private static function overEntries(Carbon $start, Carbon $end, \Closure $category): array
    {
        $entries = [];

        foreach (Donation::whereBetween('created_at', [$start, $end])->get() as $d) {
            $entries[] = [
                'id' => 'donation-' . $d->id,
                'source' => 'donation',
                'itemId' => $d->inventory_item_id ? (string) $d->inventory_item_id : null,
                'itemName' => $d->item_name,
                'category' => $category($d->inventory_item_id),
                'quantity' => (int) $d->quantity,
                'date' => ($d->date ?? $d->created_at)->toIso8601String(),
                'person' => $d->donor_name,
                'reference' => $d->receipt_number,
                'reason' => $d->purpose,
                'unitCost' => isset($d->unit_cost) ? (float) $d->unit_cost : null,
                'deedStatus' => $d->deed_status ?? null,
            ];
        }

        if (Schema::hasColumn('replacement_obligations', 'surplus_quantity')) {
            $surplus = ReplacementObligation::with('student')
                ->where('surplus_quantity', '>', 0)
                ->whereBetween('updated_at', [$start, $end])
                ->get();

            foreach ($surplus as $ob) {
                $owed = (int) $ob->amount;
                $given = $owed + (int) $ob->surplus_quantity;
                $entries[] = [
                    'id' => 'surplus-' . $ob->id,
                    'source' => 'replacement_surplus',
                    'itemId' => (string) $ob->item_id,
                    'itemName' => $ob->item_name,
                    'category' => $category($ob->item_id, $ob->item_category),
                    'quantity' => (int) $ob->surplus_quantity,
                    'date' => ($ob->resolution_date ?? $ob->updated_at)->toIso8601String(),
                    'person' => self::fullName($ob->student, 'Unknown Student'),
                    'reference' => self::requestCode($ob->borrow_request_id),
                    'reason' => "Returned {$given} for {$owed} {$ob->type}",
                ];
            }
        }

        if (Schema::hasColumn('borrow_request_items', 'over_returned_quantity')) {
            $extra = BorrowRequestItem::with('borrowRequest.student')
                ->where('over_returned_quantity', '>', 0)
                ->whereBetween('inspection_date', [$start, $end])
                ->get();

            foreach ($extra as $line) {
                $entries[] = [
                    'id' => 'over-return-' . $line->id,
                    'source' => 'over_return',
                    'itemId' => (string) $line->item_id,
                    'itemName' => $line->name,
                    'category' => $category($line->item_id, $line->category),
                    'quantity' => (int) $line->over_returned_quantity,
                    'date' => $line->inspection_date->toIso8601String(),
                    'person' => self::fullName($line->borrowRequest?->student, 'Unknown Student'),
                    'reference' => self::requestCode($line->borrow_request_id),
                    'reason' => 'Extra units handed in at return',
                ];
            }
        }

        return array_merge($entries, self::adjustmentEntries($start, $end, $category, true));
    }

    /**
     * A custodian's deliberate stock adjustments in the period. Walk-in and
     * confidential movements use the same mechanism but are loans, not
     * adjustments, so they are left out here.
     */
    private static function adjustmentEntries(Carbon $start, Carbon $end, \Closure $category, bool $additions): array
    {
        $entries = [];

        foreach (self::adjustments($start, $end) as $adj) {
            if ($adj['transfer'] || $adj['delta'] === 0 || ($adj['delta'] > 0) !== $additions) {
                continue;
            }

            $entries[] = [
                'id' => 'adjustment-' . $adj['id'],
                'source' => 'adjustment',
                'itemId' => (string) $adj['itemId'],
                'itemName' => $adj['itemName'],
                'category' => $category($adj['itemId']),
                'quantity' => abs($adj['delta']),
                'date' => $adj['at']->toIso8601String(),
                'person' => $adj['actor'],
                'reference' => null,
                'reason' => $adj['reason'] ?: 'No reason given',
            ] + ($additions ? [] : [
                'kind' => 'adjustment',
                'open' => false,
                'daysOutstanding' => 0,
                'dueDate' => null,
                'carriedFrom' => null,
                'noticeSent' => false,
            ]);
        }

        return $entries;
    }

    /**
     * Every logged change to an item's count that carried an adjustment reason.
     *
     * @return Collection<int, array{id:int,itemId:int,itemName:string,delta:int,reason:string,actor:string,at:Carbon,transfer:bool}>
     */
    private static function adjustments(Carbon $start, Carbon $end): Collection
    {
        return InventoryActivityLog::whereBetween('timestamp', [$start, $end])
            ->where('entity_type', 'item')
            ->get()
            ->filter(fn ($log) => isset(($log->metadata ?? [])['adjustment']))
            ->map(function ($log) {
                $delta = 0;
                foreach (($log->changes ?? []) as $c) {
                    if (in_array($c['field'] ?? '', ['quantity', 'donations'], true)) {
                        $delta += (int) ($c['newValue'] ?? 0) - (int) ($c['oldValue'] ?? 0);
                    }
                }
                $reason = trim((string) ($log->metadata['adjustment']['reason'] ?? ''));

                return [
                    'id' => $log->id,
                    'itemId' => (int) $log->entity_id,
                    'itemName' => $log->entity_name,
                    'delta' => $delta,
                    'reason' => $reason,
                    'actor' => $log->user_name,
                    'at' => Carbon::parse($log->timestamp),
                    'transfer' => (bool) preg_match('/^(walk-in|confidential)/i', $reason),
                ];
            })
            ->values();
    }

    /** Units not on the shelf right now, each with the person who has or owes them. */
    private static function openShortages(Carbon $now, \Closure $category): array
    {
        $entries = [];
        $monthStart = $now->copy()->startOfMonth();
        $daysSince = fn (?Carbon $at) => $at ? max(0, (int) floor($at->diffInDays($now, true))) : 0;

        $loans = BorrowRequest::with(['items', 'student'])
            ->whereIn('status', BorrowRequest::OUT_STATUSES)
            ->get();

        foreach ($loans as $req) {
            $overdue = $req->return_date && $req->return_date->lt($now);
            // A request can list the same item on more than one line; the
            // borrower holds them as one quantity.
            $lines = $req->items->groupBy('item_id')->map(function ($group) {
                $line = $group->first();
                $line->quantity = $group->sum('quantity');
                return $line;
            });

            foreach ($lines as $line) {
                $entries[] = [
                    'id' => 'loan-' . $line->id,
                    'kind' => 'unreturned',
                    'itemId' => (string) $line->item_id,
                    'itemName' => $line->name,
                    'category' => $category($line->item_id, $line->category),
                    'quantity' => (int) $line->quantity,
                    'date' => ($req->picked_up_at ?? $req->created_at)->toIso8601String(),
                    'person' => self::fullName($req->student, 'Unknown Student'),
                    'reference' => self::requestCode($req->id),
                    'requestId' => (string) $req->id,
                    'reason' => $req->status === 'pending_return'
                        ? 'Handed back, awaiting inspection'
                        : ($overdue ? 'Not returned by the due date' : 'Out on loan'),
                    'open' => true,
                    'daysOutstanding' => $daysSince($req->picked_up_at ?? $req->created_at),
                    'daysOverdue' => $overdue ? max(1, (int) ceil($req->return_date->diffInHours($now, true) / 24)) : 0,
                    'dueDate' => $req->return_date?->toIso8601String(),
                    'carriedFrom' => ($req->picked_up_at && $req->picked_up_at->lt($monthStart)) ? $req->picked_up_at->format('F Y') : null,
                    'noticeSent' => (bool) ($req->overdue_notice_sent_at ?? false),
                ];
            }
        }

        $remarks = Schema::hasColumn('borrow_request_items', 'inspection_remarks')
            ? BorrowRequestItem::whereNotNull('inspection_remarks')
                ->whereIn('borrow_request_id', ReplacementObligation::where('status', 'pending')->select('borrow_request_id'))
                ->get()
                ->keyBy(fn ($i) => $i->borrow_request_id . ':' . $i->item_id)
            : collect();

        foreach (ReplacementObligation::with('student')->where('status', 'pending')->get() as $ob) {
            $balance = max(0, (int) $ob->amount - (int) $ob->amount_paid);
            if ($balance === 0) {
                continue;
            }

            $pastDue = $ob->due_date && $ob->due_date->lt($now);
            $entries[] = [
                'id' => 'obligation-' . $ob->id,
                'kind' => $ob->type,
                'itemId' => (string) $ob->item_id,
                'itemName' => $ob->item_name,
                'category' => $category($ob->item_id, $ob->item_category),
                'quantity' => $balance,
                'date' => $ob->incident_date->toIso8601String(),
                'person' => self::fullName($ob->student, 'Unknown Student'),
                'reference' => self::requestCode($ob->borrow_request_id),
                'requestId' => (string) $ob->borrow_request_id,
                'reason' => $remarks[$ob->borrow_request_id . ':' . $ob->item_id]->inspection_remarks
                    ?? self::lastRemark($ob->incident_notes),
                'open' => true,
                'daysOutstanding' => $daysSince($ob->incident_date),
                'daysOverdue' => $pastDue ? max(1, (int) ceil($ob->due_date->diffInHours($now, true) / 24)) : 0,
                'dueDate' => $ob->due_date?->toIso8601String(),
                'carriedFrom' => $ob->incident_date->lt($monthStart) ? $ob->incident_date->format('F Y') : null,
                'noticeSent' => false,
            ];
        }

        foreach (WalkInTransaction::with('items')->where('status', 'borrowed')->get() as $walkIn) {
            $overdue = $walkIn->return_date && Carbon::parse($walkIn->return_date)->lt($now);
            $borrowedAt = Carbon::parse($walkIn->borrow_date ?? $walkIn->created_at);
            foreach ($walkIn->items as $line) {
                $entries[] = [
                    'id' => 'walk-in-' . $line->id,
                    'kind' => 'unreturned',
                    'itemId' => $line->item_id ? (string) $line->item_id : null,
                    'itemName' => $line->name,
                    'category' => $category($line->item_id, $line->category),
                    'quantity' => (int) $line->quantity,
                    'date' => $borrowedAt->toIso8601String(),
                    'person' => $walkIn->student_name . ' (walk-in)',
                    'reference' => $walkIn->reference,
                    'requestId' => null,
                    'reason' => $overdue ? 'Not returned by the due date' : 'Out on loan',
                    'open' => true,
                    'daysOutstanding' => $daysSince($borrowedAt),
                    'daysOverdue' => $overdue ? max(1, (int) ceil(Carbon::parse($walkIn->return_date)->diffInHours($now, true) / 24)) : 0,
                    'dueDate' => $walkIn->return_date ? Carbon::parse($walkIn->return_date)->toIso8601String() : null,
                    'carriedFrom' => $borrowedAt->lt($monthStart) ? $borrowedAt->format('F Y') : null,
                    'noticeSent' => false,
                ];
            }
        }

        return $entries;
    }

    /** The custodian's own words from a formatted incident note. */
    private static function lastRemark(?string $notes): ?string
    {
        if (!$notes) {
            return null;
        }
        $pos = strripos($notes, 'Remarks:');
        $tail = $pos !== false ? trim(substr($notes, $pos + 8)) : trim($notes);

        return ($tail === '' || $tail[0] === '[') ? null : $tail;
    }

    /**
     * Items whose count moved since the month began without a recorded cause:
     * the shelf, loans and replacements do not add up to the starting count
     * plus what was logged as coming in or going out. These need a recount.
     */
    private static function unexplained(Collection $items, ?object $opening): array
    {
        if (!$opening) {
            return [];
        }

        // Timestamps are stored to the second, so a movement in the same
        // second as the closing belongs to the new month.
        $since = Carbon::parse($opening->closed_at)->startOfSecond();
        $now = Carbon::now();
        $outstanding = self::outstanding();

        $logged = [];
        $add = function ($itemId, int $units) use (&$logged) {
            if ($itemId) {
                $logged[(int) $itemId] = ($logged[(int) $itemId] ?? 0) + $units;
            }
        };

        foreach (self::adjustments($since, $now) as $adj) {
            $add($adj['itemId'], $adj['delta']);
        }
        foreach (Donation::where('created_at', '>=', $since)->get() as $d) {
            $add($d->inventory_item_id, (int) $d->quantity);
        }
        if (Schema::hasColumn('replacement_obligations', 'surplus_quantity')) {
            foreach (ReplacementObligation::where('surplus_quantity', '>', 0)->where('updated_at', '>=', $since)->get() as $ob) {
                $add($ob->item_id, (int) $ob->surplus_quantity);
            }
        }
        if (Schema::hasColumn('borrow_request_items', 'over_returned_quantity')) {
            foreach (BorrowRequestItem::where('over_returned_quantity', '>', 0)->where('inspection_date', '>=', $since)->get() as $line) {
                $add($line->item_id, (int) $line->over_returned_quantity);
            }
        }

        $rows = [];
        foreach ($items as $item) {
            // Items added after the month began start from their own count.
            if ($item->archived || ($item->created_at && $item->created_at->gt($since))) {
                continue;
            }

            $actual = (int) $item->quantity + (int) $item->donations
                + ($outstanding[$item->id]['out'] ?? 0)
                + ($outstanding[$item->id]['owed'] ?? 0);
            $expected = (int) $item->eom_count + ($logged[$item->id] ?? 0);
            $difference = $actual - $expected;

            if ($difference !== 0) {
                $rows[] = [
                    'itemId' => (string) $item->id,
                    'itemName' => $item->name,
                    'category' => $item->category,
                    'difference' => $difference,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        usort($rows, fn ($a, $b) => abs($b['difference']) <=> abs($a['difference']));

        return $rows;
    }
}
