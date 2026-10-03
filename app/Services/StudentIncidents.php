<?php

namespace App\Services;

use App\Models\ReplacementObligation;
use App\Models\WalkInTransactionItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Damaged and missing items, per registered student, from both places they
 * are recorded:
 *
 *  - Borrow requests: one ReplacementObligation per affected item, with a
 *    replacement status (pending / replaced) and a due date.
 *  - Walk-in transactions: the inspection result on the walk-in item. Walk-ins
 *    have no replacement workflow, so these are "recorded" only.
 *
 * Guests borrowing through the walk-in desk have no student account and are
 * not included; there is no record to attach their history to.
 */
class StudentIncidents
{
    /**
     * Incident rows, newest first.
     *
     * @param array{
     *     from?: ?Carbon,
     *     to?: ?Carbon,
     *     studentIds?: ?array,
     *     includeRequests?: bool,
     *     includeWalkIns?: bool,
     * } $options studentIds null = everyone; [] = nobody.
     */
    public static function collect(array $options = []): Collection
    {
        $from = $options['from'] ?? null;
        $to = $options['to'] ?? null;
        $studentIds = $options['studentIds'] ?? null;
        $includeRequests = $options['includeRequests'] ?? true;
        $includeWalkIns = $options['includeWalkIns'] ?? true;

        if (is_array($studentIds) && count($studentIds) === 0) {
            return collect();
        }

        $obligations = !$includeRequests ? collect() : ReplacementObligation::query()
            ->when($studentIds !== null, fn($q) => $q->whereIn('student_id', $studentIds))
            ->when($from, fn($q) => $q->where('incident_date', '>=', $from))
            ->when($to, fn($q) => $q->where('incident_date', '<=', $to))
            ->get()
            ->map(fn(ReplacementObligation $o) => [
                'id' => 'ro-' . $o->id,
                'source' => 'request',
                'reference' => 'REQ-' . strtoupper(substr((string) $o->borrow_request_id, -6)),
                'requestId' => (string) $o->borrow_request_id,
                'studentId' => (string) $o->student_id,
                'type' => $o->type,
                'itemName' => $o->item_name,
                'category' => $o->item_category,
                'quantity' => (int) $o->amount,
                'outstanding' => max(0, (int) $o->amount - (int) $o->amount_paid),
                'status' => $o->status, // pending | replaced
                'incidentAt' => $o->incident_date?->toIso8601String(),
                'dueDate' => $o->due_date?->toIso8601String(),
                'resolvedAt' => $o->resolution_date?->toIso8601String(),
                'notes' => $o->incident_notes,
            ]);

        $walkIns = collect();
        if ($includeWalkIns) {
            $walkIns = WalkInTransactionItem::query()
                ->with('transaction')
                ->whereIn('inspection_status', ['damaged', 'missing'])
                ->whereHas('transaction', function ($q) use ($studentIds, $from, $to) {
                    $q->whereNotNull('student_id')
                        ->when($studentIds !== null, fn($t) => $t->whereIn('student_id', $studentIds))
                        // Inspected at return; fall back to the last update for older rows.
                        ->when($from, fn($t) => $t->where(DB::raw('COALESCE(returned_at, updated_at)'), '>=', $from))
                        ->when($to, fn($t) => $t->where(DB::raw('COALESCE(returned_at, updated_at)'), '<=', $to));
                })
                ->get()
                ->map(function (WalkInTransactionItem $i) {
                    $t = $i->transaction;
                    return [
                        'id' => 'wi-' . $i->id,
                        'source' => 'walk_in',
                        'reference' => $t->reference,
                        'requestId' => null,
                        'studentId' => (string) $t->student_id,
                        'type' => $i->inspection_status,
                        'itemName' => $i->name,
                        'category' => $i->category,
                        'quantity' => (int) ($i->replacement_quantity ?? $i->quantity),
                        'outstanding' => null, // walk-ins have no replacement tracking
                        'status' => 'recorded',
                        'incidentAt' => ($t->returned_at ?? $t->updated_at)?->toIso8601String(),
                        'dueDate' => $i->due_date?->toIso8601String(),
                        'resolvedAt' => null,
                        'notes' => $i->inspection_notes ?? $t->notes,
                    ];
                });
        }

        return $obligations->concat($walkIns)
            ->sortByDesc('incidentAt')
            ->values();
    }

    /**
     * Students an instructor may see: those enrolled in any class they teach.
     *
     * @return array<int>
     */
    public static function studentIdsForInstructor(int $instructorId): array
    {
        return DB::table('class_code_student')
            ->whereIn('class_code_id', DB::table('class_code_instructor')
                ->where('user_id', $instructorId)
                ->select('class_code_id'))
            ->distinct()
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * Students enrolled in any of the given classes.
     *
     * @return array<int>
     */
    public static function studentIdsForClasses(array $classCodeIds): array
    {
        return DB::table('class_code_student')
            ->whereIn('class_code_id', $classCodeIds)
            ->distinct()
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }
}
