<?php

namespace App\Http\Controllers;

use App\Models\ReplacementObligation;
use App\Models\User;
use App\Services\StudentIncidents;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Damage & Missing History for Reports & Analytics.
 *
 * All-time by default: a student's record should not disappear because the
 * report's date range moved on. Pass from/to to narrow it to a period.
 *
 * Staff see every student. Instructors see only students enrolled in the
 * classes they teach.
 */
class StudentIncidentReportController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'custodian', 'superadmin', 'instructor'];

    /** Rows shown in the past-due replacements list; the counts stay exact. */
    private const PAST_DUE_LIMIT = 20;

    /**
     * GET /api/reports/student-incidents
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            [$from, $to] = $this->range($request);
            $studentIds = $this->visibleStudentIds($request, $user);

            $incidents = StudentIncidents::collect([
                'from' => $from,
                'to' => $to,
                'studentIds' => $studentIds,
            ]);

            $users = User::whereIn('id', $incidents->pluck('studentId')->unique())->get()->keyBy('id');

            $students = $incidents->groupBy('studentId')
                ->map(fn(Collection $rows, $id) => $this->summarise($users->get($id), (string) $id, $rows))
                ->values();

            if ($search = trim((string) $request->query('search', ''))) {
                $needle = mb_strtolower($search);
                $students = $students->filter(fn($s) => str_contains(mb_strtolower($s['studentName'] . ' ' . ($s['email'] ?? '')), $needle))->values();
            }

            $students = $students->sortBy([
                ['total', 'desc'],
                ['lastIncidentAt', 'desc'],
            ])->values();

            return response()->json([
                'range' => ['from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String()],
                'totals' => [
                    'students' => $students->count(),
                    'incidents' => $students->sum('total'),
                    'damaged' => $students->sum('damaged'),
                    'missing' => $students->sum('missing'),
                    'pending' => $students->sum('pending'),
                    'walkIn' => $students->sum('walkIn'),
                ],
                'students' => $students,
                'replacementAging' => $this->replacementAging($studentIds),
                'generatedAt' => Carbon::now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to build student incident report: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load damage and missing history'], 500);
        }
    }

    /**
     * GET /api/reports/student-incidents/{studentId}
     */
    public function show(Request $request, $studentId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $student = User::where('role', 'student')->find($studentId);
        if (!$student) {
            return response()->json(['error' => 'Student not found'], 404);
        }

        if ($user->role === 'instructor'
            && !in_array((int) $student->id, StudentIncidents::studentIdsForInstructor((int) $user->id), true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        [$from, $to] = $this->range($request);
        $incidents = StudentIncidents::collect([
            'from' => $from,
            'to' => $to,
            'studentIds' => [(int) $student->id],
        ]);

        return response()->json([
            'student' => $this->summarise($student, (string) $student->id, $incidents),
            'incidents' => $incidents,
            'range' => ['from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String()],
        ]);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;
        return [$from, $to];
    }

    /**
     * null = no restriction. Instructors are always restricted to their own
     * students; a class filter narrows further to students enrolled in it.
     */
    private function visibleStudentIds(Request $request, User $user): ?array
    {
        $ids = null;

        if ($user->role === 'instructor') {
            $ids = StudentIncidents::studentIdsForInstructor((int) $user->id);
        }

        $classIds = $request->query('class_code_id');
        if ($classIds !== null && $classIds !== '') {
            $classIds = is_array($classIds) ? $classIds : explode(',', (string) $classIds);
            $inClasses = StudentIncidents::studentIdsForClasses(array_filter($classIds));
            $ids = $ids === null ? $inClasses : array_values(array_intersect($ids, $inClasses));
        }

        return $ids;
    }

    private function summarise(?User $student, string $id, Collection $rows): array
    {
        $tracked = $rows->where('source', 'request');

        return [
            'studentId' => $id,
            'studentName' => $student ? trim("{$student->first_name} {$student->last_name}") : 'Unknown Student',
            'email' => $student?->email,
            'profilePhotoUrl' => $student?->profile_photo_url,
            'yearLevel' => $student?->year_level,
            'block' => $student?->block,
            'total' => $rows->count(),
            'damaged' => $rows->where('type', 'damaged')->count(),
            'missing' => $rows->where('type', 'missing')->count(),
            'unitsAffected' => (int) $rows->sum('quantity'),
            'pending' => $tracked->where('status', 'pending')->count(),
            'outstandingUnits' => (int) $tracked->where('status', 'pending')->sum('outstanding'),
            'walkIn' => $rows->where('source', 'walk_in')->count(),
            'lastIncidentAt' => $rows->max('incidentAt'),
        ];
    }

    /**
     * Unpaid replacements by how long they have been open, and which are past
     * their due date. Always current state, not limited to the report range.
     */
    private function replacementAging(?array $studentIds): array
    {
        $now = Carbon::now();

        $pending = ReplacementObligation::with('student')
            ->where('status', 'pending')
            ->when($studentIds !== null, fn($q) => $q->whereIn('student_id', $studentIds))
            ->get();

        // Whole days open; Carbon 3 returns a fractional, signed difference.
        $ageDays = fn(ReplacementObligation $o) => $o->incident_date ? (int) floor($o->incident_date->diffInDays($now)) : 0;

        $pastDue = $pending
            ->filter(fn($o) => $o->due_date && $o->due_date->lt($now))
            ->sortBy('due_date')
            ->values();

        return [
            'open' => $pending->count(),
            'within7Days' => $pending->filter(fn($o) => $ageDays($o) <= 7)->count(),
            'within30Days' => $pending->filter(fn($o) => $ageDays($o) > 7 && $ageDays($o) <= 30)->count(),
            'over30Days' => $pending->filter(fn($o) => $ageDays($o) > 30)->count(),
            'pastDue' => $pastDue->count(),
            'pastDueItems' => $pastDue->take(self::PAST_DUE_LIMIT)->map(fn($o) => [
                'id' => (string) $o->id,
                'studentId' => (string) $o->student_id,
                'studentName' => $o->student ? trim("{$o->student->first_name} {$o->student->last_name}") : 'Unknown Student',
                'itemName' => $o->item_name,
                'type' => $o->type,
                'outstanding' => max(0, (int) $o->amount - (int) $o->amount_paid),
                'dueDate' => $o->due_date->toIso8601String(),
                'daysPastDue' => (int) floor($o->due_date->diffInDays($now)),
                'reference' => 'REQ-' . strtoupper(substr((string) $o->borrow_request_id, -6)),
            ])->values(),
        ];
    }
}
