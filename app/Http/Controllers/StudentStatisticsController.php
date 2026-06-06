<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ClassCode;
use App\Models\BorrowRequest;
use App\Models\InventoryItem;
use App\Services\StudentStatisticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class StudentStatisticsController extends Controller
{
    /**
     * GET /api/student-statistics
     * 
     * Returns student reliability scores and transaction analytics.
     */
    public function getStats(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->role !== 'student') {
            return response()->json(['error' => 'Forbidden: statistics endpoint is restricted to students'], 403);
        }

        $period = $request->query('period', '180d');
        if (!in_array($period, ['7d', '30d', '90d', '180d', '365d', 'all'])) {
            return response()->json(['error' => 'Invalid period. Supported: 7d, 30d, 90d, 180d, 365d, all'], 400);
        }

        try {
            $stats = StudentStatisticsService::computeStudentStatistics($user->id, $period);
            return response()->json($stats);
        } catch (\Exception $e) {
            Log::error('Failed to compute student statistics: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to compute statistics'], 500);
        }
    }

    /**
     * GET /api/dashboard/stats
     * 
     * Returns counters for main user dashboard widgets based on authenticated user's role.
     */
    public function getDashboardStats(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            if ($user->role === 'superadmin') {
                $totalUsers = User::count();
                $studentCount = User::where('role', 'student')->count();
                $instructorCount = User::where('role', 'instructor')->count();
                $custodianCount = User::where('role', 'custodian')->count();
                $superadminCount = User::where('role', 'superadmin')->count();

                $totalClassCodes = ClassCode::count();
                $totalRequests = BorrowRequest::count();
                $totalInventoryItems = InventoryItem::count();

                $sevenDaysAgo = Carbon::now()->subDays(7);
                $newUsersThisWeek = User::where('created_at', '>=', $sevenDaysAgo)->count();
                $activeUsersThisWeek = User::where('last_login', '>=', $sevenDaysAgo)->count();

                $recentUsers = User::orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(['id', 'email', 'role', 'first_name', 'last_name', 'created_at']);

                return response()->json([
                    'totalUsers' => $totalUsers,
                    'students' => $studentCount,
                    'instructors' => $instructorCount,
                    'custodians' => $custodianCount,
                    'superadmins' => $superadminCount,
                    'totalClassCodes' => $totalClassCodes,
                    'totalRequests' => $totalRequests,
                    'totalInventoryItems' => $totalInventoryItems,
                    'newUsersThisWeek' => $newUsersThisWeek,
                    'activeUsersThisWeek' => $activeUsersThisWeek,
                    'staffMembers' => $instructorCount + $custodianCount,
                    'recentUsers' => $recentUsers->map(fn($u) => [
                        'id' => (string) $u->id,
                        'email' => $u->email,
                        'role' => $u->role,
                        'firstName' => $u->first_name,
                        'lastName' => $u->last_name,
                        'createdAt' => $u->created_at->toIso8601String()
                    ])->toArray()
                ]);
            } elseif ($user->role === 'instructor' || $user->role === 'custodian') {
                $studentCount = User::where('role', 'student')->count();

                return response()->json([
                    'students' => $studentCount,
                    'role' => $user->role
                ]);
            } else {
                return response()->json([
                    'role' => $user->role,
                    'message' => 'Welcome to your dashboard'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to retrieve dashboard stats: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
