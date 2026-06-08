<?php

namespace App\Services;

use App\Models\User;
use App\Models\BorrowRequest;
use App\Models\ReplacementObligation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AiChatService
{
    private static $BASE_SYSTEM_PROMPT = <<<TEXT
You are an intelligent assistant for CHTM Cooks - a laboratory equipment management system used by a culinary and hospitality school.

## Assistant Identity
Your assistant name is ARIA, which stands for AI Requisition & Inventory Assistant.

## About the System
CHTM Cooks is a digital platform that manages the borrowing, tracking, and return of culinary laboratory equipment. It serves three types of users:

- Students - Browse available equipment, submit borrow requests, track request status, and return items.
- Instructors - Review and approve or reject student borrow requests, monitor currently borrowed equipment, and manage unresolved incidents.
- Custodians - Prepare approved equipment for pickup, confirm student pickups, process item returns with condition inspection, manage inventory, and handle replacement obligations.

## Key Workflows You Can Explain

### How to Submit a Borrow Request (Students)
1. Log in to the student portal.
2. Browse the equipment catalog and select the items needed.
3. Specify the borrow date, return date, and purpose.
4. Submit the request - it goes to the instructor for review.
5. Once the instructor approves, the custodian prepares the items.
6. The student picks up the equipment from the custodian when notified.
7. On the return date, the student returns the items to the custodian for inspection.

### Request Status Flow
- Pending - Awaiting instructor approval.
- In Preparation - Instructor approved; custodian is preparing the items.
- Ready for Pickup - Items are staged and ready for the student to collect.
- Currently Borrowed - Student has picked up the equipment.
- Return Requested - Student has initiated the return process.
- Returned / Completed - Items returned and inspected; request closed.
- Unresolved - Items reported damaged or missing; replacement obligations may apply.
- Cancelled / Rejected - Request was cancelled by the student or rejected by the instructor.

### Equipment and Inventory
- The catalog lists all available culinary equipment (knives, bowls, mixers, scales, processors, etc.).
- Each item has a name, category, condition, quantity, and availability status.
- Required items always appear on request forms regardless of stock.
- Low-stock and out-of-stock items are flagged for custodian attention.

### Replacement Obligations
- If a returned item is found damaged or missing during inspection, a replacement obligation is created.
- The student is notified and must fulfill the obligation before borrowing again.

### Reports and Analytics
- Instructors and custodians can view usage reports, borrow trends, and equipment utilization data.
- Student analytics include a Trust Score computed from borrowing behavior and obligations.

## How to Respond
- Always use a professional, industry-standard support tone.
- Be concise, clear, and practical. Prioritize accurate, actionable guidance over long explanations.
- Maintain proper grammar and punctuation. Avoid slang, sarcasm, jokes, emojis, and filler language.
- Use neutral, respectful language suitable for academic and administrative users.
- Prefer structured answers with short headings, numbered steps, or bullets when it improves clarity.
- For simple greetings (for example: "hello", "hi", "good morning"), reply with a short welcome and one question about what the user needs.
- If the user asks your name or identity, answer clearly as: "I am ARIA (AI Requisition & Inventory Assistant)."
- Do not proactively provide account metrics, request counts, or obligation summaries unless the user explicitly asks for status, summary, dashboard, activity, requests, or obligations.
- When explaining a workflow, use this order when relevant:
	1. Direct answer
	2. Steps or details
	3. What to do next
- If the user asks for a process, provide the exact portal path or action sequence when known.
- If the user asks about equipment availability, explain how to check the catalog and interpret stock indicators.
- If the user asks about request status, define the status and the responsible role behind it.
- If the user asks about returns or damaged items, explain inspection, unresolved status, and replacement obligation handling.
- Do not claim that trust score is unavailable if student analytics context includes trust-score fields.
- If the user intent is unclear, ask one brief clarifying question before guessing.
- If you do not know something specific about the user's account, clearly state that limitation and direct them to the relevant portal section.
- Never invent account data, approvals, inventory counts, or request status. Only describe system behavior and what the user can do next.
- Stay on topic and only answer questions related to CHTM Cooks and its features.
TEXT;

    private static function buildRoleGuidance(string $role): string
    {
        if ($role === 'student') {
            return <<<TEXT

## Role Context
The current authenticated user role is Student.

## Student-Specific Guidance Rules
- Prioritize student tasks: requesting equipment, tracking request status, pickup, return steps, and obligations.
- Provide clear student action items with prerequisites and deadlines.
- For policy-sensitive actions (approval, inspection, inventory edits), explain that these are handled by Instructor or Custodian roles.
- Do not suggest actions that require elevated permissions.
TEXT;
        }

        if ($role === 'instructor') {
            return <<<TEXT

## Role Context
The current authenticated user role is Instructor.

## Instructor-Specific Guidance Rules
- Prioritize instructor tasks: reviewing requests, approval/rejection decisions, monitoring currently borrowed equipment, and unresolved incidents.
- Emphasize decision criteria, workflow checkpoints, and communication with students/custodians.
- When asked about actions outside instructor permissions (inventory preparation/inspection), direct to Custodian workflows.
- Keep recommendations auditable and policy-aligned.
TEXT;
        }

        if ($role === 'custodian') {
            return <<<TEXT

## Role Context
The current authenticated user role is Custodian.

## Custodian-Specific Guidance Rules
- Prioritize custodian tasks: preparing approved items, confirming pickup, return inspection, inventory updates, and replacement obligations.
- Focus on operational accuracy: item condition checks, quantity verification, and status transitions.
- For approval decisions, clearly state those are instructor-controlled actions.
- Provide step-by-step procedures that reduce handling errors.
TEXT;
        }

        return <<<TEXT

## Role Context
The current user role is not explicitly available.

## Guidance Rule
- Ask one brief clarifying question for role (Student, Instructor, or Custodian) before giving role-specific workflow steps when role context is required.
TEXT;
    }

    /**
     * Build a lightweight user context snapshot using only simple count queries.
     * Used by the AI chat endpoint where response time is critical.
     * Deliberately avoids the expensive StudentStatisticsService computation.
     */
    public static function buildLightUserContextSnapshot(int $userId, string $role): ?array
    {
        $user = User::find($userId);
        if (!$user) {
            return null;
        }

        $metrics = [];
        try {
            if ($role === 'student') {
                $metrics = [
                    'pendingApproval'    => BorrowRequest::where('student_id', $userId)->where('status', 'pending_instructor')->count(),
                    'readyForPickup'     => BorrowRequest::where('student_id', $userId)->where('status', 'ready_for_pickup')->count(),
                    'currentlyBorrowed'  => BorrowRequest::where('student_id', $userId)->whereIn('status', ['borrowed', 'pending_return'])->count(),
                    'pendingObligations' => ReplacementObligation::where('student_id', $userId)->where('status', 'pending')->count(),
                ];
            } elseif ($role === 'instructor') {
                $metrics = [
                    'assignedPendingReviews' => BorrowRequest::where('instructor_id', $userId)->where('status', 'pending_instructor')->count(),
                    'assignedActiveRequests' => BorrowRequest::where('instructor_id', $userId)->whereIn('status', ['approved_instructor', 'ready_for_pickup', 'borrowed', 'pending_return'])->count(),
                ];
            } elseif ($role === 'custodian') {
                $metrics = [
                    'itemsToPrepare'     => BorrowRequest::where('custodian_id', $userId)->where('status', 'approved_instructor')->count(),
                    'readyForPickup'     => BorrowRequest::where('custodian_id', $userId)->where('status', 'ready_for_pickup')->count(),
                    'returnInspections'  => BorrowRequest::where('custodian_id', $userId)->where('status', 'pending_return')->count(),
                    'pendingObligations' => ReplacementObligation::where('status', 'pending')->count(),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('AiChat: Light context metrics error', ['error' => $e->getMessage()]);
        }

        return [
            'userId'         => (string) $user->id,
            'role'           => $role,
            'name'           => trim("{$user->first_name} {$user->last_name}"),
            'email'          => $user->email,
            'yearLevel'      => $user->year_level,
            'block'          => $user->block,
            'isActive'       => (bool) $user->is_active,
            'lastLogin'      => $user->last_login ? $user->last_login->toIso8601String() : null,
            'metrics'        => $metrics,
            'generatedAt'    => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Compile User Context Snapshot for Prompt.
     */
    public static function buildUserContextSnapshot(int $userId, string $role): ?array
    {
        $user = User::find($userId);
        if (!$user) {
            return null;
        }

        $metrics = [];
        try {
            if ($role === 'student') {
                $pendingApproval = BorrowRequest::where('student_id', $userId)
                    ->where('status', 'pending_instructor')
                    ->count();

                $readyForPickup = BorrowRequest::where('student_id', $userId)
                    ->where('status', 'ready_for_pickup')
                    ->count();

                $currentlyBorrowed = BorrowRequest::where('student_id', $userId)
                    ->whereIn('status', ['borrowed', 'pending_return'])
                    ->count();

                $pendingObligations = ReplacementObligation::where('student_id', $userId)
                    ->where('status', 'pending')
                    ->count();

                $trustScore = 'unavailable';
                $trustTier = 'unavailable';
                try {
                    $stats = StudentStatisticsService::computeStudentStatistics($userId, '180d');
                    $trustScore = $stats['trustScore']['score'];
                    $trustTier = $stats['trustScore']['tierLabel'];
                } catch (\Exception $e) {
                    Log::error('AI Chat Statistics computation error: ' . $e->getMessage());
                }

                $metrics = [
                    'pendingApproval' => $pendingApproval,
                    'readyForPickup' => $readyForPickup,
                    'currentlyBorrowed' => $currentlyBorrowed,
                    'pendingObligations' => $pendingObligations,
                    'trustScore' => $trustScore,
                    'trustTier' => $trustTier
                ];
            } elseif ($role === 'instructor') {
                $last30Days = Carbon::now()->subDays(30);

                $assignedPendingReviews = BorrowRequest::where('instructor_id', $userId)
                    ->where('status', 'pending_instructor')
                    ->count();

                $assignedActiveRequests = BorrowRequest::where('instructor_id', $userId)
                    ->whereIn('status', ['approved_instructor', 'ready_for_pickup', 'borrowed', 'pending_return'])
                    ->count();

                $approvedLast30Days = BorrowRequest::where('instructor_id', $userId)
                    ->where('status', 'approved_instructor')
                    ->where('approved_at', '>=', $last30Days)
                    ->count();

                $metrics = [
                    'assignedPendingReviews' => $assignedPendingReviews,
                    'assignedActiveRequests' => $assignedActiveRequests,
                    'approvedLast30Days' => $approvedLast30Days
                ];
            } elseif ($role === 'custodian') {
                $itemsToPrepare = BorrowRequest::where('custodian_id', $userId)
                    ->where('status', 'approved_instructor')
                    ->count();

                $readyForPickup = BorrowRequest::where('custodian_id', $userId)
                    ->where('status', 'ready_for_pickup')
                    ->count();

                $returnInspections = BorrowRequest::where('custodian_id', $userId)
                    ->where('status', 'pending_return')
                    ->count();

                $pendingObligations = ReplacementObligation::where('status', 'pending')->count();

                $metrics = [
                    'itemsToPrepare' => $itemsToPrepare,
                    'readyForPickup' => $readyForPickup,
                    'returnInspections' => $returnInspections,
                    'pendingObligations' => $pendingObligations
                ];
            }
        } catch (\Exception $e) {
            Log::error('AI Chat Metrics context build error: ' . $e->getMessage());
        }

        return [
            'userId' => (string) $user->id,
            'role' => $role,
            'name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'yearLevel' => $user->year_level,
            'block' => $user->block,
            'isActive' => (bool) $user->is_active,
            'lastLogin' => $user->last_login ? $user->last_login->toIso8601String() : null,
            'metrics' => $metrics,
            'generatedAt' => Carbon::now()->toIso8601String()
        ];
    }

    /**
     * Format context block string.
     */
    public static function buildUserContextPrompt(?array $snapshot): string
    {
        if (!$snapshot) {
            return <<<TEXT

## Authenticated User Context
- No authenticated user snapshot is available for this request.
- If the user asks for account-specific guidance, ask them to sign in and then retry.
TEXT;
        }

        $metricLines = "";
        foreach ($snapshot['metrics'] as $key => $value) {
            $metricLines .= "- {$key}: {$value}\n";
        }

        $classLine = "";
        if ($snapshot['role'] === 'student' && ($snapshot['yearLevel'] || $snapshot['block'])) {
            $yearLevel = $snapshot['yearLevel'] ?: 'N/A';
            $block = $snapshot['block'] ?: 'N/A';
            $classLine = "- classContext: {$yearLevel} / {$block}\n";
        }

        $accountActive = $snapshot['isActive'] ? 'true' : 'false';
        $lastLogin = $snapshot['lastLogin'] ?: 'unknown';

        return <<<TEXT

## Authenticated User Context
- userId: {$snapshot['userId']}
- role: {$snapshot['role']}
- name: {$snapshot['name']}
- email: {$snapshot['email']}
{$classLine}- accountActive: {$accountActive}
- lastLogin: {$lastLogin}
- roleMetrics:
{$metricLines}- snapshotGeneratedAt: {$snapshot['generatedAt']}

## Context Usage Rules
- Use this context to personalize answers for this authenticated user.
- Do not reveal role metrics by default. Share counts only when the user explicitly asks for status, summary, dashboard, requests, or obligations.
- For basic greetings, keep the reply brief and do not include account snapshots.
- Treat these values as read-only facts and do not invent extra profile data.
- If asked for data outside this snapshot, explain the limitation and provide the exact portal page/action to verify it.
TEXT;
    }

    /**
     * Compile system prompt.
     */
    public static function getSystemInstruction(string $role, ?array $contextSnapshot = null): string
    {
        $prompt = self::$BASE_SYSTEM_PROMPT . self::buildRoleGuidance($role);
        $prompt .= self::buildUserContextPrompt($contextSnapshot);
        return $prompt;
    }
}
