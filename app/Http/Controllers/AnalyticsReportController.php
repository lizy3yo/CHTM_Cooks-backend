<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\BorrowRequest;
use App\Models\BorrowRequestItem;
use App\Models\ReplacementObligation;
use App\Models\Donation;
use App\Models\InventoryItem;
use App\Services\StudentStatisticsService;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsReportController extends Controller
{
    private function getPeriodRange(string $period, ?string $from = null, ?string $to = null): array
    {
        $end = $to ? Carbon::parse($to) : Carbon::now();
        $end->endOfDay();

        if ($from) {
            $start = Carbon::parse($from)->startOfDay();
            return ['start' => $start, 'end' => $end, 'label' => $start->format('M j, Y') . ' - ' . $end->format('M j, Y')];
        }

        $start = $end->copy();
        if ($period === 'week') {
            $start->subDays(7)->startOfDay();
            $label = 'Last 7 Days';
        } elseif ($period === 'semester') {
            $start->subMonths(6)->startOfDay();
            $label = 'Last 6 Months';
        } else {
            $start->subMonth()->startOfDay();
            $label = 'Month-to-Date';
        }
        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    private function getReportData(Carbon $start, Carbon $end, string $period): array
    {
        $now = Carbon::now();

        // 1. Borrow Requests
        $requests = BorrowRequest::with(['items', 'student', 'instructor', 'custodian'])
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // Overdue count & list
        $overdueCount = BorrowRequest::where('status', 'borrowed')
            ->where('return_date', '<', $now)
            ->count();

        $overdueList = BorrowRequest::with('student')
            ->where('status', 'borrowed')
            ->where('return_date', '<', $now)
            ->orderBy('return_date', 'asc')
            ->limit(20)
            ->get();

        $overdueRequests = $overdueList->map(function ($r) use ($now) {
            return [
                '_id' => (string) $r->id,
                'studentName' => $r->student ? ($r->student->first_name . ' ' . $r->student->last_name) : 'Unknown Student',
                'returnDate' => $r->return_date ? $r->return_date->toIso8601String() : null,
                'daysOverdue' => $r->return_date ? round($now->diffInHours($r->return_date) / 24, 1) : 0,
                'itemCount' => $r->items->count()
            ];
        })->toArray();

        // Group requests by date for chart
        $requestsOverTime = [];
        $timelineGroup = $requests->groupBy(fn($r) => $r->created_at->format('Y-m-d'));
        foreach ($timelineGroup as $date => $group) {
            $requestsOverTime[] = [
                'date' => $date,
                'count' => $group->count()
            ];
        }
        usort($requestsOverTime, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Status Breakdown
        $statusBreakdown = [];
        $statusGroup = $requests->groupBy('status');
        foreach ($statusGroup as $status => $group) {
            $statusBreakdown[] = [
                'status' => $status,
                'count' => $group->count()
            ];
        }

        // Peak Heatmap (dayOfWeek vs hour)
        $peakHeatmap = [];
        $heatmapCounts = [];
        foreach ($requests as $r) {
            $day = $r->created_at->dayOfWeek + 1; // 1 = Sun, 7 = Sat
            $hour = $r->created_at->hour;
            $key = "{$day}-{$hour}";
            $heatmapCounts[$key] = ($heatmapCounts[$key] ?? 0) + 1;
        }
        foreach ($heatmapCounts as $key => $count) {
            list($day, $hour) = explode('-', $key);
            $peakHeatmap[] = [
                'dayOfWeek' => (int) $day,
                'hour' => (int) $hour,
                'count' => $count
            ];
        }

        // Turnaround
        $turnaroundRequests = BorrowRequest::whereBetween('created_at', [$start, $end])
            ->whereNotNull('returned_at')
            ->get();
        $totalApprovalHours = 0; $approvalCount = 0;
        $totalReleaseHours = 0; $releaseCount = 0;
        $totalReturnHours = 0; $returnCount = 0;

        foreach ($turnaroundRequests as $r) {
            if ($r->approved_at && $r->created_at) {
                $totalApprovalHours += $r->approved_at->diffInHours($r->created_at);
                $approvalCount++;
            }
            if ($r->released_at && $r->approved_at) {
                $totalReleaseHours += $r->released_at->diffInHours($r->approved_at);
                $releaseCount++;
            }
            if ($r->returned_at && $r->released_at) {
                $totalReturnHours += $r->returned_at->diffInHours($r->released_at);
                $returnCount++;
            }
        }

        $turnaround = [
            'avgApprovalHours' => $approvalCount > 0 ? round($totalApprovalHours / $approvalCount, 1) : 0,
            'avgReleaseHours' => $releaseCount > 0 ? round($totalReleaseHours / $releaseCount, 1) : 0,
            'avgReturnHours' => $returnCount > 0 ? round($totalReturnHours / $returnCount, 1) : 0
        ];

        // Averages
        $totalRequests = $requests->count();
        $totalItemsCount = 0;
        $totalQuantityCount = 0;
        foreach ($requests as $r) {
            $totalItemsCount += $r->items->count();
            $totalQuantityCount += $r->items->sum('quantity');
        }

        $borrowingAverages = [
            'avgItemsPerRequest' => $totalRequests > 0 ? round($totalItemsCount / $totalRequests, 1) : 0,
            'avgQuantityPerRequest' => $totalRequests > 0 ? round($totalQuantityCount / $totalRequests, 1) : 0,
            'totalRequests' => $totalRequests
        ];

        // Items Borrowed
        $itemsBorrowedMap = [];
        foreach ($requests as $r) {
            foreach ($r->items as $item) {
                $itemId = $item->item_id;
                if (!isset($itemsBorrowedMap[$itemId])) {
                    $itemsBorrowedMap[$itemId] = [
                        'id' => (string) $itemId,
                        'name' => $item->name,
                        'category' => $item->category,
                        'totalQuantity' => 0,
                        'borrowCount' => 0
                    ];
                }
                $itemsBorrowedMap[$itemId]['totalQuantity'] += $item->quantity;
                $itemsBorrowedMap[$itemId]['borrowCount'] += 1;
            }
        }
        $itemsBorrowed = array_values($itemsBorrowedMap);
        usort($itemsBorrowed, fn($a, $b) => $b['totalQuantity'] - $a['totalQuantity']);

        // Item Entries
        $itemEntries = [];
        foreach ($requests as $r) {
            foreach ($r->items as $item) {
                $itemEntries[] = [
                    'id' => $r->id . ':' . $item->item_id,
                    'requestId' => (string) $r->id,
                    'requestDate' => $r->created_at->toIso8601String(),
                    'requestStatus' => $r->status,
                    'name' => $item->name,
                    'category' => $item->category,
                    'quantity' => $item->quantity,
                    'studentName' => $r->student ? ($r->student->first_name . ' ' . $r->student->last_name) : 'Unknown Student',
                    'studentEmail' => $r->student ? $r->student->email : 'N/A'
                ];
            }
        }

        // Borrowers
        $borrowersMap = [];
        foreach ($requests as $r) {
            if (!$r->student_id) continue;
            $studentId = $r->student_id;
            if (!isset($borrowersMap[$studentId])) {
                $borrowersMap[$studentId] = [
                    '_id' => (string) $studentId,
                    'studentName' => $r->student ? ($r->student->first_name . ' ' . $r->student->last_name) : 'Unknown Student',
                    'studentEmail' => $r->student ? $r->student->email : 'N/A',
                    'requestCount' => 0,
                    'totalItems' => 0
                ];
            }
            $borrowersMap[$studentId]['requestCount'] += 1;
            $borrowersMap[$studentId]['totalItems'] += $r->items->count();
        }
        $borrowers = array_values($borrowersMap);
        usort($borrowers, fn($a, $b) => $b['requestCount'] - $a['requestCount']);

        // 2. Loss & Damage
        $obligations = ReplacementObligation::with(['student', 'borrowRequest'])
            ->whereBetween('incident_date', [$start, $end])
            ->get();

        $todayStart = Carbon::today();
        $last7DaysStart = Carbon::now()->subDays(7)->startOfDay();
        $monthStart = Carbon::now()->startOfMonth();

        $lossAndDamageSummary = [
            'todayTotal' => $obligations->filter(fn($o) => $o->incident_date >= $todayStart)->count(),
            'todayMissing' => $obligations->filter(fn($o) => $o->incident_date >= $todayStart && $o->type === 'missing')->count(),
            'todayDamaged' => $obligations->filter(fn($o) => $o->incident_date >= $todayStart && $o->type === 'damaged')->count(),
            'last7DaysTotal' => $obligations->filter(fn($o) => $o->incident_date >= $last7DaysStart)->count(),
            'last7DaysMissing' => $obligations->filter(fn($o) => $o->incident_date >= $last7DaysStart && $o->type === 'missing')->count(),
            'last7DaysDamaged' => $obligations->filter(fn($o) => $o->incident_date >= $last7DaysStart && $o->type === 'damaged')->count(),
            'mtdTotal' => $obligations->filter(fn($o) => $o->incident_date >= $monthStart)->count(),
            'mtdMissing' => $obligations->filter(fn($o) => $o->incident_date >= $monthStart && $o->type === 'missing')->count(),
            'mtdDamaged' => $obligations->filter(fn($o) => $o->incident_date >= $monthStart && $o->type === 'damaged')->count(),
            'periodTotal' => $obligations->count(),
            'periodMissing' => $obligations->where('type', 'missing')->count(),
            'periodDamaged' => $obligations->where('type', 'damaged')->count()
        ];

        $lossAndDamageTracking = $obligations->slice(0, 30)->map(function ($o) {
            $req = $o->borrowRequest;
            return [
                '_id' => (string) $o->id,
                'type' => $o->type,
                'status' => $o->status,
                'itemName' => $o->item_name,
                'itemCategory' => $o->item_category,
                'amount' => $o->amount,
                'amountPaid' => $o->amount_paid,
                'incidentDate' => $o->incident_date ? $o->incident_date->toIso8601String() : null,
                'resolutionDate' => $o->resolution_date ? $o->resolution_date->toIso8601String() : null,
                'resolutionType' => $o->resolution_type,
                'studentName' => $o->student ? ($o->student->first_name . ' ' . $o->student->last_name) : 'Unknown Student',
                'requestId' => (string) $o->borrow_request_id,
                'requestStatus' => $req ? $req->status : null,
                'requestCreatedAt' => $req ? $req->created_at->toIso8601String() : null,
                'requestReturnedAt' => $req ? ($req->returned_at ? $req->returned_at->toIso8601String() : null) : null,
                'daysToResolve' => ($o->resolution_date && $o->incident_date) ? round($o->resolution_date->diffInHours($o->incident_date) / 24, 1) : null
            ];
        })->values()->toArray();

        // 3. Inventory
        $currentItems = InventoryItem::where('archived', false)->get();
        $totalStockQty = $currentItems->sum('quantity');
        $totalEomQty = $currentItems->sum('eom_count');
        $totalDonationsQty = $currentItems->sum('donations');
        $requiredCount = $currentItems->where('is_required', true)->count();
        $lowStockCount = 0;
        $outOfStockCount = 0;

        // Stock adjustments (donations with donor_name = 'Custodian Stock Adjustment')
        $adjustments = Donation::where('donor_name', 'Custodian Stock Adjustment')
            ->whereBetween('created_at', [$start, $end])
            ->get();
        
        $totalAdjustedAdded = $adjustments->filter(fn($a) => $a->quantity > 0)->sum('quantity');
        $totalAdjustedDeducted = abs($adjustments->filter(fn($a) => $a->quantity < 0)->sum('quantity'));

        $inventorySummary = [
            'currentCount' => $totalStockQty,
            'eomCount' => $totalEomQty,
            'variance' => $totalStockQty - $totalEomQty,
            'donations' => $totalDonationsQty,
            'requiredCount' => $requiredCount,
            'lowStockCount' => $lowStockCount,
            'outOfStockCount' => $outOfStockCount,
            'stockAdjustmentsAdded' => $totalAdjustedAdded,
            'stockAdjustmentsDeducted' => $totalAdjustedDeducted,
            'stockAdjustmentsCount' => $adjustments->count()
        ];

        $requiredItems = $currentItems->where('is_required', true)->sortByDesc('quantity')->slice(0, 20)->map(function ($i) {
            return [
                'id' => (string) $i->id,
                'name' => $i->name,
                'category' => $i->category,
                'quantity' => $i->quantity,
                'eomCount' => $i->eom_count,
                'variance' => $i->quantity - $i->eom_count,
                'donations' => $i->donations
            ];
        })->values()->toArray();

        $mostBorrowedItems = array_slice($itemsBorrowed, 0, 10);

        // Items currently out
        $outItemsMap = [];
        $activeBorrowings = BorrowRequest::with('items')->where('status', 'borrowed')->get();
        foreach ($activeBorrowings as $b) {
            foreach ($b->items as $item) {
                $itemId = $item->item_id;
                if (!isset($outItemsMap[$itemId])) {
                    $outItemsMap[$itemId] = [
                        '_id' => (string) $itemId,
                        'name' => $item->name,
                        'category' => $item->category,
                        'quantityOut' => 0,
                        'totalStock' => 0
                    ];
                }
                $outItemsMap[$itemId]['quantityOut'] += $item->quantity;
            }
        }
        // Fill total stock
        foreach ($outItemsMap as $itemId => $outData) {
            $invItem = InventoryItem::find($itemId);
            if ($invItem) {
                $outItemsMap[$itemId]['totalStock'] = $invItem->quantity;
            }
        }
        $itemsCurrentlyOut = array_values($outItemsMap);
        usort($itemsCurrentlyOut, fn($a, $b) => $b['quantityOut'] - $a['quantityOut']);
        $itemsCurrentlyOut = array_slice($itemsCurrentlyOut, 0, 20);

        // Damage rate items
        $inspectedItemsMap = [];
        $completedRequests = BorrowRequest::with('items')
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', ['returned', 'missing', 'resolved'])
            ->get();
        foreach ($completedRequests as $r) {
            foreach ($r->items as $item) {
                if (!$item->inspection_status) continue;
                $itemId = $item->item_id;
                if (!isset($inspectedItemsMap[$itemId])) {
                    $inspectedItemsMap[$itemId] = [
                        'id' => (string) $itemId,
                        'name' => $item->name,
                        'category' => $item->category,
                        'totalInspected' => 0,
                        'damaged' => 0,
                        'missing' => 0
                    ];
                }
                $inspectedItemsMap[$itemId]['totalInspected'] += 1;
                if ($item->inspection_status === 'damaged') {
                    $inspectedItemsMap[$itemId]['damaged'] += 1;
                } elseif ($item->inspection_status === 'missing') {
                    $inspectedItemsMap[$itemId]['missing'] += 1;
                }
            }
        }
        $damageRateItems = [];
        foreach ($inspectedItemsMap as $itemId => $ins) {
            if ($ins['totalInspected'] >= 2) {
                $ins['incidentRate'] = round((($ins['damaged'] + $ins['missing']) / $ins['totalInspected']) * 100, 1);
                $damageRateItems[] = $ins;
            }
        }
        usort($damageRateItems, fn($a, $b) => $b['incidentRate'] <=> $a['incidentRate']);
        $damageRateItems = array_slice($damageRateItems, 0, 10);

        // EOM Variance list
        $eomVariance = $currentItems->filter(fn($i) => ($i->quantity - $i->eom_count) !== 0)->map(function ($i) {
            return [
                '_id' => (string) $i->id,
                'name' => $i->name,
                'category' => $i->category,
                'quantity' => $i->quantity,
                'eomCount' => $i->eom_count,
                'variance' => $i->quantity - $i->eom_count
            ];
        })->sortByDesc(fn($i) => abs($i['variance']))->slice(0, 200)->values()->toArray();

        // Variance Drivers
        $varianceDriversMap = [];
        foreach ($requests as $r) {
            foreach ($r->items as $item) {
                $itemId = $item->item_id;
                if (!isset($varianceDriversMap[$itemId])) {
                    $varianceDriversMap[$itemId] = [
                        '_id' => (string) $itemId,
                        'name' => $item->name,
                        'category' => $item->category,
                        'requestCount' => 0,
                        'totalBorrowedQuantity' => 0,
                        'latestRequestId' => (string) $r->id,
                        'latestRequestDate' => $r->created_at->toIso8601String(),
                        'latestRequestStatus' => $r->status,
                        'studentName' => $r->student ? ($r->student->first_name . ' ' . $r->student->last_name) : 'Unknown Student',
                        'studentEmail' => $r->student ? $r->student->email : 'N/A',
                        'studentProfilePhotoUrl' => $r->student ? $r->student->profile_photo_url : null
                    ];
                }
                $varianceDriversMap[$itemId]['requestCount'] += 1;
                $varianceDriversMap[$itemId]['totalBorrowedQuantity'] += $item->quantity;
            }
        }
        $varianceDrivers = array_values($varianceDriversMap);
        usort($varianceDrivers, fn($a, $b) => $b['totalBorrowedQuantity'] - $a['totalBorrowedQuantity']);
        $varianceDrivers = array_slice($varianceDrivers, 0, 20);

        // Stock alerts (removed)
        $stockAlerts = [];

        // Stock adjustments activity logs
        $stockAdjustments = $adjustments->sortByDesc('created_at')->map(function ($a) {
            return [
                'id' => (string) $a->id,
                'itemName' => $a->item_name,
                'quantity' => $a->quantity,
                'purpose' => $a->purpose,
                'notes' => $a->notes,
                'createdAt' => $a->created_at->toIso8601String(),
                'date' => $a->date ? $a->date->toIso8601String() : null
            ];
        })->values()->toArray();

        // 4. Replacement
        $allObligations = ReplacementObligation::get();
        $totalItemsPending = $allObligations->where('status', 'pending')->sum(fn($o) => $o->amount - $o->amount_paid);
        $totalItemsReplaced = $allObligations->where('status', 'replaced')->sum('amount_paid');

        $replacementSummary = [
            'totalItemsPending' => $totalItemsPending,
            'totalItemsReplaced' => $totalItemsReplaced,
            'totalObligations' => $allObligations->count(),
            'pendingCount' => $allObligations->where('status', 'pending')->count()
        ];

        // Resolution Breakdown
        $resolutionBreakdown = [];
        $resolutionGroup = $allObligations->where('status', 'replaced')->groupBy('resolution_type');
        foreach ($resolutionGroup as $type => $group) {
            $resolutionBreakdown[] = [
                'type' => $type ?: 'replacement',
                'count' => $group->count(),
                'total' => $group->sum('amount_paid'),
                'totalAmount' => $group->sum('amount_paid')
            ];
        }

        // Avg resolution days
        $resolvedObligations = $allObligations->where('status', 'replaced')->whereNotNull('resolution_date')->whereNotNull('incident_date');
        $totalResolutionDays = 0; $resolvedCount = 0;
        foreach ($resolvedObligations as $o) {
            $totalResolutionDays += $o->resolution_date->diffInHours($o->incident_date) / 24;
            $resolvedCount++;
        }
        $avgResolutionDays = $resolvedCount > 0 ? round($totalResolutionDays / $resolvedCount, 1) : 0;

        // Obligations by Category
        $obligationsByCategory = [];
        $oblCategoryGroup = $allObligations->groupBy('item_category');
        foreach ($oblCategoryGroup as $category => $group) {
            $obligationsByCategory[] = [
                'category' => $category ?: 'Uncategorized',
                'count' => $group->count(),
                'totalAmount' => $group->sum('amount'),
                'pendingAmount' => $group->where('status', 'pending')->sum(fn($o) => $o->amount - $o->amount_paid)
            ];
        }
        usort($obligationsByCategory, fn($a, $b) => $b['count'] - $a['count']);
        $obligationsByCategory = array_slice($obligationsByCategory, 0, 10);

        // Monthly replacement activity (last 6 months)
        $sixMonthsAgo = Carbon::now()->subMonths(6)->startOfMonth();
        $monthlyActivity = [];
        $resolvedIn6Months = ReplacementObligation::where('status', 'replaced')
            ->where('resolution_date', '>=', $sixMonthsAgo)
            ->get()
            ->groupBy(fn($o) => $o->resolution_date->format('Y-m'));

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $key = $date->format('Y-m');
            $group = $resolvedIn6Months[$key] ?? collect();
            $monthlyActivity[] = [
                'year' => $date->year,
                'month' => $date->month,
                'collected' => $group->sum('amount_paid'),
                'totalAmount' => $group->sum('amount_paid'),
                'count' => $group->count()
            ];
        }

        // Donation totals (last 6 months)
        $donationTotals = [];
        $donationsIn6Months = Donation::where('donor_name', '!=', 'Custodian Stock Adjustment')
            ->where('created_at', '>=', $sixMonthsAgo)
            ->get()
            ->groupBy(fn($d) => $d->created_at->format('Y-m') . '|' . $d->item_name);

        foreach ($donationsIn6Months as $compKey => $group) {
            list($yearMonth, $itemName) = explode('|', $compKey);
            $date = Carbon::parse($yearMonth . '-01');
            $donationTotals[] = [
                'year' => $date->year,
                'month' => $date->month,
                'itemName' => $itemName,
                'count' => $group->count(),
                'totalQuantity' => $group->sum('quantity')
            ];
        }

        // 5. Student Risk
        // Repeat Offenders (most pending obligations)
        $repeatOffenders = ReplacementObligation::where('status', 'pending')
            ->with('student')
            ->get()
            ->groupBy('student_id')
            ->map(function ($group) {
                $student = $group->first()->student;
                return [
                    '_id' => (string) $group->first()->student_id,
                    'studentName' => $student ? ($student->first_name . ' ' . $student->last_name) : 'Unknown Student',
                    'studentEmail' => $student ? $student->email : 'N/A',
                    'profilePhotoUrl' => $student ? $student->profile_photo_url : null,
                    'activeObligations' => $group->count(),
                    'totalBalance' => $group->sum(fn($o) => $o->amount - $o->amount_paid)
                ];
            })->sortByDesc('activeObligations')->slice(0, 10)->values()->toArray();

        // High Incident Students
        $highIncidentStudents = ReplacementObligation::whereBetween('incident_date', [$start, $end])
            ->with('student')
            ->get()
            ->groupBy('student_id')
            ->map(function ($group) {
                $student = $group->first()->student;
                return [
                    '_id' => (string) $group->first()->student_id,
                    'studentName' => $student ? ($student->first_name . ' ' . $student->last_name) : 'Unknown Student',
                    'studentEmail' => $student ? $student->email : 'N/A',
                    'profilePhotoUrl' => $student ? $student->profile_photo_url : null,
                    'incidents' => $group->count(),
                    'missingCount' => $group->where('type', 'missing')->count(),
                    'damagedCount' => $group->where('type', 'damaged')->count()
                ];
            })->sortByDesc('incidents')->slice(0, 10)->values()->toArray();

        // Overdue Students
        $overdueStudents = BorrowRequest::where('status', 'borrowed')
            ->where('return_date', '<', $now)
            ->with('student')
            ->get()
            ->groupBy('student_id')
            ->map(function ($group) use ($now) {
                $student = $group->first()->student;
                $oldestReturn = $group->min('return_date');
                return [
                    '_id' => (string) $group->first()->student_id,
                    'studentName' => $student ? ($student->first_name . ' ' . $student->last_name) : 'Unknown Student',
                    'studentEmail' => $student ? $student->email : 'N/A',
                    'profilePhotoUrl' => $student ? $student->profile_photo_url : null,
                    'overdueCount' => $group->count(),
                    'oldestDue' => $oldestReturn ? $oldestReturn->toIso8601String() : null,
                    'daysOverdue' => $oldestReturn ? round($now->diffInHours($oldestReturn) / 24, 1) : 0
                ];
            })->sortByDesc('daysOverdue')->slice(0, 10)->values()->toArray();

        // Trust Scores list (active students in this period, limit 50)
        $activeStudentIds = BorrowRequest::whereBetween('created_at', [$start, $end])
            ->whereNotNull('student_id')
            ->groupBy('student_id')
            ->orderByRaw('MAX(created_at) DESC')
            ->limit(50)
            ->pluck('student_id');

        $studentRecords = User::where('role', 'student')
            ->whereIn('id', $activeStudentIds)
            ->get();

        $trustScores = [];
        foreach ($studentRecords as $student) {
            $stats = StudentStatisticsService::computeStudentStatistics($student->id, '180d');
            $trustScores[] = [
                '_id' => (string) $student->id,
                'studentName' => trim("{$student->first_name} {$student->last_name}") ?: 'Unknown',
                'studentEmail' => $student->email,
                'profilePhotoUrl' => $student->profile_photo_url,
                'trustScore' => $stats['trustScore']['score'],
                'trustTier' => $stats['trustScore']['tier'],
                'trustTierLabel' => $stats['trustScore']['tierLabel'],
                'totalPenalties' => $stats['trustScore']['totalPenalties'],
                'totalBonuses' => $stats['trustScore']['totalBonuses'],
                'requestsTotal' => $stats['requests']['total'],
                'requestsReturned' => $stats['requests']['returned'],
                'activeObligations' => $stats['replacement']['pendingCount'],
                'dataQuality' => $stats['dataQuality']
            ];
        }

        return [
            'meta' => [
                'period' => $period,
                'from' => $start->toIso8601String(),
                'to' => $end->toIso8601String(),
                'generatedAt' => $now->toIso8601String()
            ],
            'borrowRequests' => [
                'requestsOverTime' => $requestsOverTime,
                'statusBreakdown' => $statusBreakdown,
                'turnaround' => $turnaround,
                'overdueCount' => $overdueCount,
                'overdueRequests' => $overdueRequests,
                'peakHeatmap' => $peakHeatmap,
                'borrowingAverages' => $borrowingAverages,
                'itemsBorrowed' => $itemsBorrowed,
                'itemEntries' => $itemEntries,
                'borrowers' => $borrowers
            ],
            'lossAndDamage' => [
                'summary' => $lossAndDamageSummary,
                'tracking' => $lossAndDamageTracking
            ],
            'inventory' => [
                'summary' => $inventorySummary,
                'requiredItems' => $requiredItems,
                'mostBorrowedItems' => $mostBorrowedItems,
                'itemsCurrentlyOut' => $itemsCurrentlyOut,
                'damageRateItems' => $damageRateItems,
                'eomVariance' => $eomVariance,
                'varianceDrivers' => $varianceDrivers,
                'stockAlerts' => $stockAlerts,
                'stockAdjustments' => $stockAdjustments
            ],
            'replacement' => [
                'summary' => $replacementSummary,
                'resolutionBreakdown' => $resolutionBreakdown,
                'avgResolutionDays' => $avgResolutionDays,
                'obligationsByCategory' => $obligationsByCategory,
                'monthlyActivity' => $monthlyActivity,
                'donationTotals' => $donationTotals
            ],
            'studentRisk' => [
                'repeatOffenders' => $repeatOffenders,
                'highIncidentStudents' => $highIncidentStudents,
                'overdueStudents' => $overdueStudents,
                'trustScores' => $trustScores
            ]
        ];
    }

    /**
     * GET /api/reports/analytics
     */
    public function getReport(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['instructor', 'custodian', 'superadmin'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $period = $request->query('period', 'month');
        $from = $request->query('from');
        $to = $request->query('to');

        if (!in_array($period, ['week', 'month', 'semester'])) {
            return response()->json(['error' => 'Invalid period. Use week, month, or semester.'], 400);
        }

        try {
            $range = $this->getPeriodRange($period, $from, $to);
            $report = $this->getReportData($range['start'], $range['end'], $period);
            return response()->json($report);
        } catch (\Exception $e) {
            Log::error('Failed to generate analytics report: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['error' => 'Failed to generate analytics report', 'detail' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/reports/analytics/summary
     */
    public function getSummary(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['instructor', 'custodian', 'superadmin'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $period = $request->query('period', 'month');
        $from = $request->query('from');
        $to = $request->query('to');

        try {
            $range = $this->getPeriodRange($period, $from, $to);
            $report = $this->getReportData($range['start'], $range['end'], $period);

            $summary = [
                'meta' => $report['meta'],
                'borrowRequests' => [
                    'borrowingAverages' => $report['borrowRequests']['borrowingAverages'],
                    'itemsBorrowed' => array_slice($report['borrowRequests']['itemsBorrowed'], 0, 6),
                    'statusBreakdown' => $report['borrowRequests']['statusBreakdown'],
                    'overdueCount' => $report['borrowRequests']['overdueCount']
                ],
                'lossAndDamage' => ['summary' => $report['lossAndDamage']['summary'], 'tracking' => []],
                'inventory' => ['summary' => $report['inventory']['summary'], 'requiredItems' => [], 'mostBorrowedItems' => [], 'itemsCurrentlyOut' => [], 'damageRateItems' => [], 'eomVariance' => [], 'varianceDrivers' => [], 'stockAlerts' => [], 'stockAdjustments' => []],
                'replacement' => ['summary' => $report['replacement']['summary'], 'resolutionBreakdown' => [], 'avgResolutionDays' => 0, 'obligationsByCategory' => [], 'monthlyActivity' => [], 'donationTotals' => []],
                'studentRisk' => ['repeatOffenders' => [], 'highIncidentStudents' => [], 'overdueStudents' => [], 'trustScores' => []]
            ];

            return response()->json($summary);
        } catch (\Exception $e) {
            Log::error('Failed to generate analytics summary: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to generate summary'], 500);
        }
    }

    /**
     * GET /api/reports/analytics/stream
     */
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
                // Simple keep alive loop
                $start = time();
                while (time() - $start < 30) {
                    echo ": keepalive\n\n";
                    flush();
                    sleep(10);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * GET /api/reports/analytics/export
     * 
     * Exports styled Excel Spreadsheet using Microsoft Excel XML (SpreadsheetML) format.
     * This supports styling (cell colors, borders, font weights) and multiple tabbed worksheets
     * without needing external zip/gd system PHP extensions.
     */
    public function export(Request $request)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['instructor', 'custodian', 'superadmin'])) {
            return response('Forbidden', 403);
        }

        $period = $request->query('period', 'month');
        $from = $request->query('from');
        $to = $request->query('to');

        try {
            $range = $this->getPeriodRange($period, $from, $to);
            $report = $this->getReportData($range['start'], $range['end'], $period);

            // Build XML payload
            $xml = $this->generateSpreadsheetML($report, $range['label'], $user);

            $filename = 'chtm-cooks-analytics-' . strtolower(str_replace(' ', '-', $range['label'])) . '-' . date('Y-m-d') . '.xml';

            return response($xml, 200, [
                'Content-Type' => 'application/vnd.ms-excel',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate export file: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response('Failed to generate export file: ' . $e->getMessage(), 500);
        }
    }

    private function generateSpreadsheetML(array $report, string $rangeLabel, User $user): string
    {
        $author = trim("{$user->first_name} {$user->last_name}");
        $created = date('Y-m-d\TH:i:s\Z');

        // Styles sheet XML
        $styles = <<<XML
  <Styles>
    <Style ss:ID="Default" ss:Name="Normal">
      <Alignment ss:Vertical="Bottom"/>
      <Borders/>
      <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/>
      <Interior/>
      <NumberFormat/>
      <Protection/>
    </Style>
    <Style ss:ID="Header">
      <Font ss:FontName="Calibri" ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11"/>
      <Interior ss:Color="#be185d" ss:Pattern="Solid"/>
      <Alignment ss:Vertical="Center" ss:Horizontal="Left" ss:WrapText="1"/>
      <Borders>
        <Top ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Bottom ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Left ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Right ss:Style="Thin" ss:Color="#E5E7EB"/>
      </Borders>
    </Style>
    <Style ss:ID="SectionTitle">
      <Font ss:FontName="Calibri" ss:Bold="1" ss:Size="12" ss:Color="#be185d"/>
      <Interior ss:Color="#fce7f3" ss:Pattern="Solid"/>
      <Alignment ss:Vertical="Center" ss:Horizontal="Left"/>
      <Borders>
        <Top ss:Style="Medium" ss:Color="#be185d"/>
        <Bottom ss:Style="Thin" ss:Color="#E5E7EB"/>
      </Borders>
    </Style>
    <Style ss:ID="DataCell">
      <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#111827"/>
      <Borders>
        <Top ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Bottom ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Left ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Right ss:Style="Thin" ss:Color="#E5E7EB"/>
      </Borders>
      <Alignment ss:Vertical="Center" ss:Horizontal="Left" ss:WrapText="1"/>
    </Style>
    <Style ss:ID="AltRow">
      <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#111827"/>
      <Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/>
      <Borders>
        <Top ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Bottom ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Left ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Right ss:Style="Thin" ss:Color="#E5E7EB"/>
      </Borders>
      <Alignment ss:Vertical="Center" ss:Horizontal="Left" ss:WrapText="1"/>
    </Style>
    <Style ss:ID="NumInteger" ss:Parent="DataCell">
      <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
      <NumberFormat ss:Format="#,##0"/>
    </Style>
    <Style ss:ID="NumIntegerAlt" ss:Parent="AltRow">
      <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
      <NumberFormat ss:Format="#,##0"/>
    </Style>
    <Style ss:ID="NumPercent" ss:Parent="DataCell">
      <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
      <NumberFormat ss:Format="0%"/>
    </Style>
    <Style ss:ID="NumPercentAlt" ss:Parent="AltRow">
      <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
      <NumberFormat ss:Format="0%"/>
    </Style>
    <Style ss:ID="NumDecimal" ss:Parent="DataCell">
      <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
      <NumberFormat ss:Format="#,##0.0"/>
    </Style>
    <Style ss:ID="NumDecimalAlt" ss:Parent="AltRow">
      <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
      <NumberFormat ss:Format="#,##0.0"/>
    </Style>
    <Style ss:ID="MetadataLabel">
      <Font ss:FontName="Calibri" ss:Bold="1" ss:Size="10" ss:Color="#be185d"/>
      <Interior ss:Color="#fdf2f8" ss:Pattern="Solid"/>
      <Borders>
        <Top ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Bottom ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Left ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Right ss:Style="Thin" ss:Color="#E5E7EB"/>
      </Borders>
      <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
    </Style>
    <Style ss:ID="MetadataValue">
      <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#111827"/>
      <Borders>
        <Top ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Bottom ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Left ss:Style="Thin" ss:Color="#E5E7EB"/>
        <Right ss:Style="Thin" ss:Color="#E5E7EB"/>
      </Borders>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    </Style>
    <Style ss:ID="DocTitle">
      <Font ss:FontName="Calibri" ss:Bold="1" ss:Size="14" ss:Color="#111827"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    </Style>
  </Styles>
XML;

        // XML Header
        $out = <<<XML
<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
  <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
    <Author>{$author}</Author>
    <Created>{$created}</Created>
    <LastSaved>{$created}</LastSaved>
    <Version>16.00</Version>
  </DocumentProperties>
  <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
    <WindowHeight>12435</WindowHeight>
    <WindowWidth>28035</WindowWidth>
    <WindowTopX>0</WindowTopX>
    <WindowTopY>0</WindowTopY>
    <ProtectStructure>False</ProtectStructure>
    <ProtectWindows>False</ProtectWindows>
  </ExcelWorkbook>
{$styles}
XML;

        // Metadata Header Table (Common to all tabs)
        $metaHeaderRows = function($titleName) use ($rangeLabel, $author) {
            $exportDate = date('F j, Y');
            return <<<XML
      <Row ss:Height="6"/>
      <Row ss:Height="38">
        <Cell ss:Index="4" ss:MergeAcross="2" ss:StyleID="DocTitle"><Data ss:Type="String">COLLEGE OF HOSPITALITY AND TOURISM MANAGEMENT\nGORDON COLLEGE, OLONGAPO CITY</Data></Cell>
        <Cell ss:Index="7" ss:StyleID="MetadataLabel"><Data ss:Type="String">Date of Inventory</Data></Cell>
        <Cell ss:StyleID="MetadataValue"><Data ss:Type="String">{$exportDate}</Data></Cell>
      </Row>
      <Row ss:Height="22">
        <Cell ss:Index="7" ss:StyleID="MetadataLabel"><Data ss:Type="String">Date Range</Data></Cell>
        <Cell ss:StyleID="MetadataValue"><Data ss:Type="String">{$rangeLabel}</Data></Cell>
      </Row>
      <Row ss:Height="22">
        <Cell ss:Index="7" ss:StyleID="MetadataLabel"><Data ss:Type="String">Counted by:</Data></Cell>
        <Cell ss:StyleID="MetadataValue"><Data ss:Type="String">{$author}</Data></Cell>
      </Row>
      <Row ss:Height="22">
        <Cell ss:Index="7" ss:StyleID="MetadataLabel"><Data ss:Type="String">Verified by:</Data></Cell>
        <Cell ss:StyleID="MetadataValue"><Data ss:Type="String"></Data></Cell>
      </Row>
      <Row ss:Height="12"/>
XML;
        };

        // Tab 1: Overview
        $br = $report['borrowRequests'];
        $ld = $report['lossAndDamage'];
        $inv = $report['inventory'];
        $sr = $report['studentRisk'];

        $totalReqs = count($br['itemEntries']);
        $totalReturned = count(array_filter($br['itemEntries'], fn($e) => $e['requestStatus'] === 'returned'));
        $retRate = $totalReqs > 0 ? round(($totalReturned / $totalReqs) * 100) : 0;

        $out .= "\n  <Worksheet ss:Name=\"Overview\">\n    <Table ss:DefaultColumnWidth=\"180\">\n";
        $out .= "      <Column ss:Width=\"180\" ss:Span=\"7\"/>\n";
        $out .= $metaHeaderRows('OVERVIEW');
        
        // Overview cards title
        $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">OVERVIEW SUMMARY METRICS</Data></Cell>\n      </Row>\n";
        $out .= "      <Row ss:Height=\"24\">\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Metric</Data></Cell>\n        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Value</Data></Cell>\n        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Context</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Metric</Data></Cell>\n        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Value</Data></Cell>\n        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Context</Data></Cell>\n";
        $out .= "      </Row>\n";
        $out .= "      <Row ss:Height=\"22\">\n";
        $out .= "        <Cell ss:StyleID=\"DataCell\"><Data ss:Type=\"String\">Total Requests</Data></Cell><Cell ss:StyleID=\"NumInteger\"><Data ss:Type=\"Number\">{$totalReqs}</Data></Cell><Cell ss:StyleID=\"DataCell\"><Data ss:Type=\"String\">Period Total</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"DataCell\"><Data ss:Type=\"String\">Return Rate</Data></Cell><Cell ss:StyleID=\"NumPercent\"><Data ss:Type=\"Number\">" . ($retRate/100) . "</Data></Cell><Cell ss:StyleID=\"DataCell\"><Data ss:Type=\"String\">Target: 90%</Data></Cell>\n";
        $out .= "      </Row>\n";
        $out .= "      <Row ss:Height=\"22\">\n";
        $out .= "        <Cell ss:StyleID=\"AltRow\"><Data ss:Type=\"String\">Overdue Items</Data></Cell><Cell ss:StyleID=\"NumIntegerAlt\"><Data ss:Type=\"Number\">{$br['overdueCount']}</Data></Cell><Cell ss:StyleID=\"AltRow\"><Data ss:Type=\"String\">Requires Attention</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"AltRow\"><Data ss:Type=\"String\">Loss/Damage (MTD) - Total</Data></Cell><Cell ss:StyleID=\"NumIntegerAlt\"><Data ss:Type=\"Number\">{$ld['summary']['mtdTotal']}</Data></Cell><Cell ss:StyleID=\"AltRow\"><Data ss:Type=\"String\">{$ld['summary']['mtdMissing']} missing, {$ld['summary']['mtdDamaged']} damaged</Data></Cell>\n";
        $out .= "      </Row>\n";
        $out .= "      <Row ss:Height=\"12\"/>\n";

        // Status breakdown
        if (count($br['statusBreakdown'])) {
            $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">BORROWING STATUS MIX</Data></Cell>\n      </Row>\n";
            $out .= "      <Row ss:Height=\"24\"><Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Status</Data></Cell><Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Count</Data></Cell></Row>\n";
            $idx = 0;
            foreach ($br['statusBreakdown'] as $sb) {
                $style = ($idx % 2 === 1) ? 'AltRow' : 'DataCell';
                $numStyle = ($idx % 2 === 1) ? 'NumIntegerAlt' : 'NumInteger';
                $label = ucwords(str_replace('_', ' ', $sb['status']));
                $out .= "      <Row><Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$label}</Data></Cell><Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$sb['count']}</Data></Cell></Row>\n";
                $idx++;
            }
            $out .= "      <Row ss:Height=\"12\"/>\n";
        }

        // Top items
        if (count($br['itemsBorrowed'])) {
            $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">TOP BORROWED ITEMS</Data></Cell>\n      </Row>\n";
            $out .= "      <Row ss:Height=\"24\"><Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Item</Data></Cell><Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Quantity</Data></Cell></Row>\n";
            $idx = 0;
            foreach (array_slice($br['itemsBorrowed'], 0, 6) as $ib) {
                $style = ($idx % 2 === 1) ? 'AltRow' : 'DataCell';
                $numStyle = ($idx % 2 === 1) ? 'NumIntegerAlt' : 'NumInteger';
                $out .= "      <Row><Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$ib['name']}</Data></Cell><Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$ib['totalQuantity']}</Data></Cell></Row>\n";
                $idx++;
            }
            $out .= "      <Row ss:Height=\"12\"/>\n";
        }
        $out .= "    </Table>\n  </Worksheet>\n";

        // Tab 2: Borrowing Analytics
        $out .= "  <Worksheet ss:Name=\"Borrowing Analytics\">\n    <Table ss:DefaultColumnWidth=\"150\">\n";
        $out .= "      <Column ss:Width=\"150\" ss:Span=\"7\"/>\n";
        $out .= $metaHeaderRows('BORROWING');
        
        $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">BORROWED ITEMS HISTORY</Data></Cell>\n      </Row>\n";
        $out .= "      <Row ss:Height=\"24\">\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Item Name</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Category</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Borrower</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Qty</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Date</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Status</Data></Cell>\n";
        $out .= "      </Row>\n";

        $idx = 0;
        foreach ($br['itemEntries'] as $entry) {
            $style = ($idx % 2 === 1) ? 'AltRow' : 'DataCell';
            $numStyle = ($idx % 2 === 1) ? 'NumIntegerAlt' : 'NumInteger';
            $date = Carbon::parse($entry['requestDate'])->format('M j, Y H:i');
            $status = ucwords(str_replace('_', ' ', $entry['requestStatus']));
            $out .= "      <Row>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($entry['name']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($entry['category']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($entry['studentName']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$entry['quantity']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$date}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$status}</Data></Cell>\n";
            $out .= "      </Row>\n";
            $idx++;
        }
        $out .= "    </Table>\n  </Worksheet>\n";

        // Tab 3: Loss & Damage
        $out .= "  <Worksheet ss:Name=\"Loss &amp; Damage\">\n    <Table ss:DefaultColumnWidth=\"150\">\n";
        $out .= "      <Column ss:Width=\"150\" ss:Span=\"7\"/>\n";
        $out .= $metaHeaderRows('LOSS_DAMAGE');
        
        $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">LOSS &amp; DAMAGE INCIDENTS LOG</Data></Cell>\n      </Row>\n";
        $out .= "      <Row ss:Height=\"24\">\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Type</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Item Name</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Student Name</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Qty Awaiting</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Qty Replaced</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Incident Date</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Status</Data></Cell>\n";
        $out .= "      </Row>\n";

        $idx = 0;
        foreach ($ld['tracking'] as $t) {
            $style = ($idx % 2 === 1) ? 'AltRow' : 'DataCell';
            $numStyle = ($idx % 2 === 1) ? 'NumIntegerAlt' : 'NumInteger';
            $date = Carbon::parse($t['incidentDate'])->format('M j, Y');
            $out .= "      <Row>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . strtoupper($t['type']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($t['itemName']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($t['studentName']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">" . ($t['amount'] - $t['amountPaid']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$t['amountPaid']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$date}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . ucwords($t['status']) . "</Data></Cell>\n";
            $out .= "      </Row>\n";
            $idx++;
        }
        $out .= "    </Table>\n  </Worksheet>\n";

        // Tab 4: Inventory
        $out .= "  <Worksheet ss:Name=\"Inventory\">\n    <Table ss:DefaultColumnWidth=\"150\">\n";
        $out .= "      <Column ss:Width=\"150\" ss:Span=\"7\"/>\n";
        $out .= $metaHeaderRows('INVENTORY');
        
        $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">INVENTORY STOCK LEVEL SUMMARY</Data></Cell>\n      </Row>\n";
        $out .= "      <Row ss:Height=\"24\">\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Item Name</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Category</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Current Stock</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Donations</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">EOM Target</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Variance</Data></Cell>\n";
        $out .= "      </Row>\n";

        $idx = 0;
        foreach ($inv['requiredItems'] as $i) {
            $style = ($idx % 2 === 1) ? 'AltRow' : 'DataCell';
            $numStyle = ($idx % 2 === 1) ? 'NumIntegerAlt' : 'NumInteger';
            $out .= "      <Row>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($i['name']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($i['category']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$i['quantity']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$i['donations']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$i['eomCount']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$i['variance']}</Data></Cell>\n";
            $out .= "      </Row>\n";
            $idx++;
        }
        $out .= "    </Table>\n  </Worksheet>\n";

        // Tab 5: Student Risk
        $out .= "  <Worksheet ss:Name=\"Student Risk\">\n    <Table ss:DefaultColumnWidth=\"150\">\n";
        $out .= "      <Column ss:Width=\"150\" ss:Span=\"7\"/>\n";
        $out .= $metaHeaderRows('STUDENT_RISK');
        
        $out .= "      <Row ss:Height=\"24\" ss:StyleID=\"SectionTitle\">\n        <Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">STUDENT TRUST PROFILE RATING</Data></Cell>\n      </Row>\n";
        $out .= "      <Row ss:Height=\"24\">\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Student Name</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Email</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Trust Score</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Trust Tier</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Total Requests</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Returned Count</Data></Cell>\n";
        $out .= "        <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Active Obligations</Data></Cell>\n";
        $out .= "      </Row>\n";

        $idx = 0;
        foreach ($sr['trustScores'] as $s) {
            $style = ($idx % 2 === 1) ? 'AltRow' : 'DataCell';
            $numStyle = ($idx % 2 === 1) ? 'NumIntegerAlt' : 'NumInteger';
            $out .= "      <Row>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($s['studentName']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($s['studentEmail']) . "</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$s['trustScore']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$s['trustTierLabel']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$s['requestsTotal']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$s['requestsReturned']}</Data></Cell>\n";
            $out .= "        <Cell ss:StyleID=\"{$numStyle}\"><Data ss:Type=\"Number\">{$s['activeObligations']}</Data></Cell>\n";
            $out .= "      </Row>\n";
            $idx++;
        }
        $out .= "    </Table>\n  </Worksheet>\n";

        $out .= "\n</Workbook>\n";
        return $out;
    }
}
