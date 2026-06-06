<?php

namespace App\Services;

use App\Models\BorrowRequest;
use App\Models\ReplacementObligation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class StudentStatisticsService
{
    private static $PERIOD_CONFIG = [
        '7d' => ['label' => 'Last 7 Days', 'days' => 7, 'timelineMonths' => 1],
        '30d' => ['label' => 'Last 30 Days', 'days' => 30, 'timelineMonths' => 2],
        '90d' => ['label' => 'Last 90 Days', 'days' => 90, 'timelineMonths' => 3],
        '180d' => ['label' => 'Last 6 Months', 'days' => 180, 'timelineMonths' => 6],
        '365d' => ['label' => 'Last 12 Months', 'days' => 365, 'timelineMonths' => 12],
        'all' => ['label' => 'All Time', 'days' => null, 'timelineMonths' => 12]
    ];

    private static function getTier(int $score): string
    {
        if ($score >= 90) return 'excellent';
        if ($score >= 75) return 'good';
        if ($score >= 60) return 'fair';
        if ($score >= 40) return 'poor';
        return 'critical';
    }

    private static function getTierLabel(int $score): string
    {
        if ($score >= 90) return 'Excellent';
        if ($score >= 75) return 'Good Standing';
        if ($score >= 60) return 'Fair';
        if ($score >= 40) return 'Poor';
        return 'Critical';
    }

    /**
     * Compute Student Statistics payload.
     */
    public static function computeStudentStatistics(int $studentId, string $period = '180d'): array
    {
        $allRequests = BorrowRequest::with('items')
            ->where('student_id', $studentId)
            ->get();

        $allObligations = ReplacementObligation::where('student_id', $studentId)
            ->get();

        $cfg = self::$PERIOD_CONFIG[$period] ?? self::$PERIOD_CONFIG['180d'];
        
        $start = null;
        if ($cfg['days'] !== null) {
            $start = Carbon::now()->subDays($cfg['days'])->startOfDay();
        }

        // Scoped periods
        $periodRequests = $allRequests->filter(function ($req) use ($start) {
            return !$start || $req->created_at >= $start;
        });

        $periodObligations = $allObligations->filter(function ($obl) use ($start) {
            return !$start || $obl->created_at >= $start;
        });

        $trustScore = self::computeTrustScore($allRequests, $allObligations);
        $requests = self::computeRequestStats($periodRequests);
        $returnPerformance = self::computeReturnPerformance($periodRequests);
        $itemHealth = self::computeItemHealth($periodRequests, $allObligations);
        $replacement = self::computeReplacementStats($allObligations, $periodObligations);
        $dataQuality = self::computeDataQuality($periodRequests);
        $chartGranularity = ($period === '7d') ? 'day' : 'month';

        $activityTimeline = ($period === '7d')
            ? self::computeActivityTimelineDaily($periodRequests)
            : self::computeActivityTimeline($periodRequests, $cfg['timelineMonths']);

        $topCategories = self::computeTopCategories($periodRequests);
        $insights = self::buildInsights($trustScore, $requests, $returnPerformance, $replacement, $dataQuality);

        return [
            'period' => $period,
            'periodLabel' => $cfg['label'],
            'chartGranularity' => $chartGranularity,
            'trustScore' => $trustScore,
            'requests' => $requests,
            'returnPerformance' => $returnPerformance,
            'itemHealth' => $itemHealth,
            'replacement' => $replacement,
            'dataQuality' => $dataQuality,
            'activityTimeline' => $activityTimeline,
            'topCategories' => $topCategories,
            'insights' => $insights,
            'computedAt' => Carbon::now()->toIso8601String()
        ];
    }

    /**
     * Compute Student Trust Score.
     */
    public static function computeTrustScore(Collection $allRequests, Collection $allObligations): array
    {
        $breakdown = [
            'missingItemPenalty' => 0,
            'damagedItemPenalty' => 0,
            'lateReturnPenalty' => 0,
            'cancelledAfterApprovalPenalty' => 0,
            'pendingObligationPenalty' => 0,
            'cleanReturnBonus' => 0,
            'resolvedObligationBonus' => 0
        ];

        foreach ($allRequests as $req) {
            $isTerminal = in_array($req->status, ['returned', 'missing', 'resolved']);
            if (!$isTerminal && !($req->status === 'cancelled' && $req->approved_at)) {
                continue;
            }

            $hasIssue = false;
            $allItemsInspected = $req->items->count() > 0;
            $allInspectionsGood = $req->items->count() > 0;

            foreach ($req->items as $item) {
                if (!$item->inspection_status) {
                    $allItemsInspected = false;
                    $allInspectionsGood = false;
                    continue;
                }

                if ($item->inspection_status === 'missing') {
                    $breakdown['missingItemPenalty'] += 15;
                    $hasIssue = true;
                    $allInspectionsGood = false;
                } elseif ($item->inspection_status === 'damaged') {
                    $breakdown['damagedItemPenalty'] += 10;
                    $hasIssue = true;
                    $allInspectionsGood = false;
                } elseif ($item->inspection_status !== 'good') {
                    $allInspectionsGood = false;
                }
            }

            $returnedOnTime = false;
            $returnTimestamp = $req->returned_at ?: $req->missing_at;
            if (in_array($req->status, ['returned', 'resolved']) && $returnTimestamp && $req->return_date) {
                $returnedAt = Carbon::parse($returnTimestamp);
                $dueDate = Carbon::parse($req->return_date);
                if ($returnedAt->greaterThan($dueDate)) {
                    $daysLate = ceil($returnedAt->diffInHours($dueDate) / 24);
                    $breakdown['lateReturnPenalty'] += min($daysLate * 2, 15);
                    $hasIssue = true;
                } else {
                    $returnedOnTime = true;
                }
            }

            if ($req->status === 'cancelled' && $req->approved_at) {
                $breakdown['cancelledAfterApprovalPenalty'] += 3;
            }

            if ($req->status === 'returned' && $returnedOnTime && $allItemsInspected && $allInspectionsGood && !$hasIssue) {
                $breakdown['cleanReturnBonus'] += 3;
            }
        }

        foreach ($allObligations as $obl) {
            if ($obl->status === 'pending') {
                $breakdown['pendingObligationPenalty'] += 3;
            } elseif ($obl->status === 'replaced') {
                $breakdown['resolvedObligationBonus'] += 2;
            }
        }

        $totalPenalties = $breakdown['missingItemPenalty'] +
            $breakdown['damagedItemPenalty'] +
            $breakdown['lateReturnPenalty'] +
            $breakdown['cancelledAfterApprovalPenalty'] +
            $breakdown['pendingObligationPenalty'];

        $totalBonuses = $breakdown['cleanReturnBonus'] + $breakdown['resolvedObligationBonus'];
        
        $score = max(0, min(100, 100 - $totalPenalties + $totalBonuses));

        return [
            'score' => $score,
            'tier' => self::getTier($score),
            'tierLabel' => self::getTierLabel($score),
            'breakdown' => $breakdown,
            'totalPenalties' => $totalPenalties,
            'totalBonuses' => $totalBonuses
        ];
    }

    private static function computeRequestStats(Collection $requests): array
    {
        $pending = 0;
        $active = 0;
        $returned = 0;
        $cancelled = 0;
        $rejected = 0;
        $missing = 0;

        foreach ($requests as $r) {
            switch ($r->status) {
                Case 'pending_instructor':
                case 'approved_instructor':
                case 'ready_for_pickup':
                    $pending++;
                    break;
                case 'borrowed':
                case 'pending_return':
                    $active++;
                    break;
                case 'returned':
                case 'resolved':
                    $returned++;
                    break;
                case 'cancelled':
                    $cancelled++;
                    break;
                case 'rejected':
                    $rejected++;
                    break;
                case 'missing':
                    $missing++;
                    break;
            }
        }

        return [
            'total' => $requests->count(),
            'pending' => $pending,
            'active' => $active,
            'returned' => $returned,
            'cancelled' => $cancelled,
            'rejected' => $rejected,
            'missing' => $missing
        ];
    }

    private static function computeReturnPerformance(Collection $requests): array
    {
        $returned = $requests->filter(fn($r) => in_array($r->status, ['returned', 'resolved']));
        $onTime = 0;
        $late = 0;
        $unknown = 0;
        $totalLateDays = 0;
        $maxDaysLate = 0;
        $eligible = 0;

        foreach ($returned as $req) {
            $returnTimestamp = $req->returned_at ?: ($req->missing_at ?: $req->updated_at);
            if (!$returnTimestamp || !$req->return_date) {
                $unknown++;
                continue;
            }

            $eligible++;
            $returnedAt = Carbon::parse($returnTimestamp);
            $dueDate = Carbon::parse($req->return_date);

            if ($returnedAt->lessThanOrEqualTo($dueDate)) {
                $onTime++;
            } else {
                $late++;
                $daysLate = ceil($returnedAt->diffInHours($dueDate) / 24);
                $totalLateDays += $daysLate;
                if ($daysLate > $maxDaysLate) {
                    $maxDaysLate = $daysLate;
                }
            }
        }

        return [
            'totalReturned' => $returned->count(),
            'onTime' => $onTime,
            'late' => $late,
            'unknown' => $unknown,
            'onTimeRate' => $eligible === 0 ? null : (int) round(($onTime / $eligible) * 100),
            'avgDaysLate' => $late === 0 ? 0 : (int) round($totalLateDays / $late),
            'maxDaysLate' => $maxDaysLate
        ];
    }

    private static function computeItemHealth(Collection $requests, Collection $allObligations): array
    {
        $goodCondition = 0;
        $damaged = 0;
        $missing = 0;
        $totalInspected = 0;

        // Map obligations: "borrowRequestId_itemId" -> status
        $obligationMap = [];
        foreach ($allObligations as $obl) {
            $key = "{$obl->borrow_request_id}_{$obl->item_id}";
            $obligationMap[$key] = $obl->status;
        }

        foreach ($requests as $req) {
            if (!in_array($req->status, ['returned', 'missing', 'resolved'])) {
                continue;
            }

            $hasAnyInspection = false;
            foreach ($req->items as $item) {
                if ($item->inspection_status) {
                    $hasAnyInspection = true;
                    break;
                }
            }

            if (!$hasAnyInspection) {
                if ($req->status === 'returned') {
                    $goodCondition += $req->items->count();
                    $totalInspected += $req->items->count();
                }
                continue;
            }

            foreach ($req->items as $item) {
                if (!$item->inspection_status) {
                    continue;
                }
                $totalInspected++;

                $status = $item->inspection_status;
                if (in_array($status, ['damaged', 'missing'])) {
                    $key = "{$req->id}_{$item->item_id}";
                    if (isset($obligationMap[$key]) && $obligationMap[$key] === 'replaced') {
                        $status = 'good';
                    }
                }

                switch ($status) {
                    case 'good':
                        $goodCondition++;
                        break;
                    case 'damaged':
                        $damaged++;
                        break;
                    case 'missing':
                        $missing++;
                        break;
                }
            }
        }

        return [
            'totalInspected' => $totalInspected,
            'goodCondition' => $goodCondition,
            'damaged' => $damaged,
            'missing' => $missing,
            'goodRate' => $totalInspected === 0 ? null : (int) round(($goodCondition / $totalInspected) * 100)
        ];
    }

    private static function computeReplacementStats(Collection $allObligations, Collection $periodObligations): array
    {
        $pendingCount = 0;
        $resolvedCount = 0;
        $totalAmount = 0;
        $amountPaid = 0;

        foreach ($allObligations as $obl) {
            $totalAmount += ($obl->amount ?? 0);
            $amountPaid += ($obl->amount_paid ?? 0);
            if ($obl->status === 'pending') {
                $pendingCount++;
            } else {
                $resolvedCount++;
            }
        }

        $periodIncurredAmount = 0;
        foreach ($periodObligations as $obl) {
            $periodIncurredAmount += ($obl->amount ?? 0);
        }

        return [
            'totalObligations' => $allObligations->count(),
            'pendingCount' => $pendingCount,
            'resolvedCount' => $resolvedCount,
            'totalAmount' => $totalAmount,
            'amountPaid' => $amountPaid,
            'balance' => $totalAmount - $amountPaid,
            'periodIncurredAmount' => $periodIncurredAmount
        ];
    }

    private static function computeActivityTimeline(Collection $requests, int $timelineMonths): array
    {
        $monthsShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $now = Carbon::now();
        $months = [];

        for ($i = $timelineMonths - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i)->startOfMonth();
            $year = $date->year;
            $monthIdx = $date->month - 1;
            
            $key = sprintf('%d-%02d', $year, $monthIdx + 1);
            $months[$key] = [
                'month' => $key,
                'label' => "{$monthsShort[$monthIdx]} {$year}",
                'requests' => 0,
                'returned' => 0
            ];
        }

        foreach ($requests as $req) {
            $d = Carbon::parse($req->created_at);
            $key = sprintf('%d-%02d', $d->year, $d->month);
            if (isset($months[$key])) {
                $months[$key]['requests']++;
                if ($req->status === 'returned') {
                    $months[$key]['returned']++;
                }
            }
        }

        return array_values($months);
    }

    private static function computeActivityTimelineDaily(Collection $requests): array
    {
        $daysShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $days = [];

        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i);
            $key = sprintf('%d-%02d-%02d', $d->year, $d->month, $d->day);
            $days[$key] = [
                'month' => $key,
                'label' => $daysShort[$d->dayOfWeek],
                'requests' => 0,
                'returned' => 0
            ];
        }

        foreach ($requests as $req) {
            $d = Carbon::parse($req->created_at);
            $key = sprintf('%d-%02d-%02d', $d->year, $d->month, $d->day);
            if (isset($days[$key])) {
                $days[$key]['requests']++;
                if ($req->status === 'returned') {
                    $days[$key]['returned']++;
                }
            }
        }

        return array_values($days);
    }

    private static function computeTopCategories(Collection $requests): array
    {
        $fulfilledStatuses = ['borrowed', 'pending_return', 'returned', 'missing'];
        $counts = [];
        $total = 0;

        foreach ($requests as $req) {
            if (!in_array($req->status, $fulfilledStatuses)) {
                continue;
            }
            foreach ($req->items as $item) {
                $cat = trim($item->category ?: 'Uncategorised');
                $counts[$cat] = ($counts[$cat] ?? 0) + $item->quantity;
                $total += $item->quantity;
            }
        }

        arsort($counts);
        $top = array_slice($counts, 0, 5, true);
        
        $result = [];
        foreach ($top as $category => $count) {
            $result[] = [
                'category' => $category,
                'count' => $count,
                'percentage' => $total === 0 ? 0 : (int) round(($count / $total) * 100)
            ];
        }

        return $result;
    }

    private static function computeDataQuality(Collection $requests): array
    {
        $terminal = $requests->filter(fn($r) => in_array($r->status, ['returned', 'missing']));
        $returned = $requests->filter(fn($r) => $r->status === 'returned');

        $terminalItems = 0;
        $inspectedItems = 0;
        foreach ($terminal as $req) {
            $terminalItems += $req->items->count();
            foreach ($req->items as $item) {
                if ($item->inspection_status) {
                    $inspectedItems++;
                }
            }
        }

        $returnTimestampComplete = 0;
        foreach ($returned as $req) {
            if ($req->returned_at && $req->return_date) {
                $returnTimestampComplete++;
            }
        }

        return [
            'inspectionCoverage' => $terminalItems === 0 ? 100 : (int) round(($inspectedItems / $terminalItems) * 100),
            'returnTimestampCoverage' => $returned->count() === 0 ? 100 : (int) round(($returnTimestampComplete / $returned->count()) * 100),
            'inspectedReturnCount' => $inspectedItems,
            'returnedCount' => $returned->count()
        ];
    }

    private static function buildInsights(array $trustScore, array $requests, array $returnPerformance, array $replacement, array $dataQuality): array
    {
        $insights = [];

        if ($replacement['pendingCount'] > 0 || $replacement['balance'] > 0) {
            $insights[] = [
                'id' => 'resolve-replacement-obligations',
                'severity' => $replacement['balance'] > 0 ? 'critical' : 'warning',
                'title' => 'Resolve outstanding obligations',
                'description' => "You have {$replacement['pendingCount']} pending obligation(s) with {$replacement['balance']} outstanding balance.",
                'actionLabel' => 'Review obligations',
                'href' => '/student/borrowed'
            ];
        }

        if ($returnPerformance['onTimeRate'] !== null && $returnPerformance['onTimeRate'] < 80) {
            $insights[] = [
                'id' => 'improve-on-time-rate',
                'severity' => 'warning',
                'title' => 'Improve return punctuality',
                'description' => "Your on-time return rate is {$returnPerformance['onTimeRate']}%. Returning items on time helps preserve your trust score."
            ];
        }

        if ($trustScore['score'] >= 90) {
            $insights[] = [
                'id' => 'excellent-trust',
                'severity' => 'success',
                'title' => 'Excellent trust standing',
                'description' => 'You have an excellent reliability profile. Continue timely and careful returns.'
            ];
        } elseif ($trustScore['score'] < 60) {
            $insights[] = [
                'id' => 'trust-recovery',
                'severity' => 'critical',
                'title' => 'Trust recovery needed',
                'description' => 'Your trust score is below 60. Focus on clean returns and resolving obligations to recover eligibility.'
            ];
        }

        if ($dataQuality['inspectionCoverage'] < 80 || $dataQuality['returnTimestampCoverage'] < 90) {
            $insights[] = [
                'id' => 'data-quality-warning',
                'severity' => 'info',
                'title' => 'Some analytics are still stabilizing',
                'description' => 'A portion of historical requests has incomplete return metadata, so some rates may improve as records are completed.'
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'id' => 'healthy-profile',
                'severity' => 'success',
                'title' => 'Healthy borrowing profile',
                'description' => 'No major risk signals detected in your current statistics window.'
            ];
        }

        return array_slice($insights, 0, 4);
    }
}
