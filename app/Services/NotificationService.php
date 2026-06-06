<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\BorrowRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private static $STATUS_LABELS = [
        'pending_instructor' => 'Under Review',
        'approved_instructor' => 'Instructor Approved',
        'ready_for_pickup' => 'Ready for Pickup',
        'borrowed' => 'Borrowed',
        'pending_return' => 'Pending Return',
        'missing' => 'Missing / Damaged',
        'resolved' => 'Resolved',
        'returned' => 'Returned',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'pending_appeal' => 'Appeal Submitted'
    ];

    private static function getRolePath(string $role): string
    {
        if ($role === 'student') return '/student/requests';
        if ($role === 'instructor') return '/instructor/requests';
        return '/custodian/requests';
    }

    private static function getRoleLabel(string $role): string
    {
        if ($role === 'student') return 'Student Portal';
        if ($role === 'instructor') return 'Instructor Portal';
        return 'Custodian Portal';
    }

    private static function getNotificationType(string $event): string
    {
        switch ($event) {
            case 'submitted': return 'borrow_request_submitted';
            case 'approved': return 'borrow_request_approved';
            case 'rejected': return 'borrow_request_rejected';
            case 'ready_for_pickup': return 'borrow_request_ready_for_pickup';
            case 'picked_up': return 'borrow_request_picked_up';
            case 'return_initiated': return 'borrow_request_return_initiated';
            case 'returned': return 'borrow_request_returned';
            case 'missing': return 'borrow_request_missing';
            case 'item_issue': return 'borrow_request_item_issue';
            case 'cancelled': return 'borrow_request_cancelled';
            case 'reminder_sent': return 'borrow_request_reminder';
            case 'appealed': return 'borrow_request_appealed';
            default: return 'borrow_request_pending_review';
        }
    }

    private static function buildCopy(string $event, string $role, string $requestStatus, string $code): array
    {
        $statusLabel = self::$STATUS_LABELS[$requestStatus] ?? $requestStatus;

        switch ($event) {
            case 'submitted':
                if ($role === 'student') {
                    return [
                        'title' => "Request submitted ({$code})",
                        'message' => "Your borrow request has been submitted and is now pending instructor review.",
                        'emailSummary' => "Your request has been received and queued for instructor review."
                    ];
                }
                return [
                    'title' => "New request under review ({$code})",
                    'message' => "A new student request requires instructor review.",
                    'emailSummary' => "A new borrow request was submitted and is waiting for review."
                ];
            case 'approved':
                if ($role === 'student') {
                    return [
                        'title' => "Instructor approved request ({$code})",
                        'message' => "Your request has been approved and will be prepared for pickup.",
                        'emailSummary' => "Your request was approved by the instructor and forwarded for preparation."
                    ];
                }
                return [
                    'title' => "Request approved for preparation ({$code})",
                    'message' => "An approved request is now awaiting custodian release.",
                    'emailSummary' => "A request has been approved and is ready for custodian processing."
                ];
            case 'rejected':
                return [
                    'title' => "Request rejected ({$code})",
                    'message' => "This request was rejected. Open details to review the reason.",
                    'emailSummary' => "The request was rejected. Please review the reason in your portal."
                ];
            case 'ready_for_pickup':
                return [
                    'title' => "Ready for pickup ({$code})",
                    'message' => "Your approved request is ready for pickup.",
                    'emailSummary' => "Your request has been prepared and is now ready for pickup."
                ];
            case 'picked_up':
                return [
                    'title' => "Items picked up ({$code})",
                    'message' => "The request has been marked as picked up and is now active.",
                    'emailSummary' => "The request items were picked up and the borrow period is active."
                ];
            case 'return_initiated':
                if ($role === 'student') {
                    return [
                        'title' => "Return request submitted ({$code})",
                        'message' => "Your return request was submitted and is pending custodian inspection.",
                        'emailSummary' => "Your return request has been submitted and is awaiting inspection."
                    ];
                }
                return [
                    'title' => "Return inspection required ({$code})",
                    'message' => "A student initiated a return and this request is now pending inspection.",
                    'emailSummary' => "A borrow request now requires return inspection."
                ];
            case 'returned':
                return [
                    'title' => "Request returned ({$code})",
                    'message' => "All items for this request were returned successfully.",
                    'emailSummary' => "The request has been completed and returned successfully."
                ];
            case 'missing':
                return [
                    'title' => "Item issue recorded ({$code})",
                    'message' => "This request has been marked with missing or damaged item issues.",
                    'emailSummary' => "This request includes missing or damaged items and requires follow-up."
                ];
            case 'item_issue':
                return [
                    'title' => "Inspection flagged item issue ({$code})",
                    'message' => "Inspection found missing or damaged items. Replacement obligations may apply.",
                    'emailSummary' => "Inspection identified item issues and follow-up actions are required."
                ];
            case 'cancelled':
                if ($role === 'student') {
                    return [
                        'title' => "Request cancelled ({$code})",
                        'message' => "Your pending request has been cancelled.",
                        'emailSummary' => "Your pending request has been cancelled."
                    ];
                }
                return [
                    'title' => "Student request cancelled ({$code})",
                    'message' => "A student cancelled a pending request.",
                    'emailSummary' => "A pending request was cancelled by the student."
                ];
            case 'reminder_sent':
                return [
                    'title' => "Overdue reminder sent ({$code})",
                    'message' => "A due-date reminder was sent for this borrow request.",
                    'emailSummary' => "This is a reminder that your borrowed items are overdue for return."
                ];
            case 'appealed':
                if ($role === 'student') {
                    return [
                        'title' => "Appeal submitted ({$code})",
                        'message' => "Your appeal has been submitted and is pending instructor review.",
                        'emailSummary' => "Your appeal has been received and is awaiting instructor review."
                    ];
                }
                return [
                    'title' => "Student appeal requires review ({$code})",
                    'message' => "A student has appealed a rejected request. Please review the appeal.",
                    'emailSummary' => "A student has submitted an appeal for a rejected borrow request."
                ];
            default:
                return [
                    'title' => "Request updated ({$code})",
                    'message' => "Request status is now {$statusLabel}.",
                    'emailSummary' => "Request status changed to {$statusLabel}."
                ];
        }
    }

    private static function getRecipients(BorrowRequest $request, string $event): array
    {
        $recipients = [];

        // Always notify the student if they are active
        $student = $request->student;
        if ($student && $student->is_active) {
            $recipients[$student->id] = [
                'user' => $student,
                'role' => 'student'
            ];
        }

        // Notify instructors
        if (in_array($event, ['submitted', 'cancelled', 'appealed'])) {
            $instructors = User::where('role', 'instructor')->where('is_active', true)->get();
            foreach ($instructors as $inst) {
                $recipients[$inst->id] = [
                    'user' => $inst,
                    'role' => 'instructor'
                ];
            }
        }

        // Notify custodians
        if (in_array($event, ['approved', 'return_initiated'])) {
            $custodians = User::where('role', 'custodian')->where('is_active', true)->get();
            foreach ($custodians as $cust) {
                $recipients[$cust->id] = [
                    'user' => $cust,
                    'role' => 'custodian'
                ];
            }
        }

        // Notify specific participants on terminal events
        if (in_array($event, ['missing', 'item_issue', 'returned', 'picked_up'])) {
            if ($request->instructor_id) {
                $inst = User::where('id', $request->instructor_id)->where('is_active', true)->first();
                if ($inst) {
                    $recipients[$inst->id] = [
                        'user' => $inst,
                        'role' => 'instructor'
                    ];
                }
            }
            if ($request->custodian_id) {
                $cust = User::where('id', $request->custodian_id)->where('is_active', true)->first();
                if ($cust) {
                    $recipients[$cust->id] = [
                        'user' => $cust,
                        'role' => 'custodian'
                    ];
                }
            }
        }

        // Filters student only for specific notifications
        if (in_array($event, ['rejected', 'ready_for_pickup', 'reminder_sent'])) {
            foreach ($recipients as $id => $rec) {
                if ($rec['role'] !== 'student') {
                    unset($recipients[$id]);
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * Dispatch borrow request notifications and emails to relevant parties.
     */
    public static function notifyBorrowRequestLifecycle(BorrowRequest $request, string $event, ?string $contextNotes = null): void
    {
        $recipients = self::getRecipients($request, $event);
        if (empty($recipients)) {
            return;
        }

        $code = "REQ-" . strtoupper(substr(strval($request->id), -6));
        $requestItems = $request->items->map(fn($i) => "{$i->name} (x{$i->quantity})")->toArray();
        $statusLabel = self::$STATUS_LABELS[$request->status] ?? $request->status;

        foreach ($recipients as $recipient) {
            $user = $recipient['user'];
            $role = $recipient['role'];
            $copy = self::buildCopy($event, $role, $request->status, $code);

            // 1. Create DB Notification
            Notification::create([
                'user_id' => $user->id,
                'audience_role' => $role,
                'type' => self::getNotificationType($event),
                'title' => $copy['title'],
                'message' => $copy['message'],
                'link' => self::getRolePath($role) . "?requestId={$request->id}",
                'borrow_request_id' => $request->id,
                'metadata' => [
                    'status' => $request->status,
                    'requestCode' => $code,
                    'event' => $event
                ],
                'is_read' => false
            ]);

            // 2. Queue Email sending
            if ($user->email) {
                $isStudent = ($role === 'student');
                dispatch(function () use ($user, $copy, $code, $statusLabel, $role, $request, $requestItems, $contextNotes, $isStudent) {
                    EmailService::sendBorrowRequestLifecycleEmail([
                        'to' => $user->email,
                        'firstName' => $user->first_name ?? 'User',
                        'title' => $copy['title'],
                        'summary' => $copy['emailSummary'],
                        'requestCode' => $code,
                        'statusLabel' => $statusLabel,
                        'roleLabel' => self::getRoleLabel($role),
                        'ctaPath' => self::getRolePath($role) . "?requestId={$request->id}",
                        'items' => $requestItems,
                        'notes' => $contextNotes,
                        'qrRawValue' => $isStudent ? strval($request->id) : null,
                        'qrCaption' => $isStudent ? 'Show this QR code to the custodian when processing this request.' : null
                    ]);
                })->afterResponse(); // Dispatch asynchronously after sending the response to SvelteKit
            }
        }
    }

    /**
     * Enrich a collection of notifications with actorName and actorPhotoUrl.
     * Keeps notifications visually appealing for student/staff UI lists.
     */
    public static function enrichNotifications($notifications)
    {
        $borrowRequestIds = $notifications->pluck('borrow_request_id')->filter()->unique();
        $borrowRequests = BorrowRequest::whereIn('id', $borrowRequestIds)->get()->keyBy('id');

        $userIds = collect();
        $notifActorIds = [];

        foreach ($notifications as $n) {
            if ($n->type === 'support_message_received') {
                continue;
            }

            $req = $n->borrow_request_id ? ($borrowRequests[$n->borrow_request_id] ?? null) : null;
            $actorId = null;
            
            $metadata = $n->metadata;
            $event = $metadata['event'] ?? null;

            if (!$req) {
                $actorId = $n->user_id;
            } else {
                if ($event) {
                    if (in_array($event, ['submitted', 'cancelled', 'return_initiated'])) {
                        $actorId = $req->student_id;
                    } elseif (in_array($event, ['approved', 'rejected'])) {
                        $actorId = $req->instructor_id;
                    } elseif (in_array($event, ['ready_for_pickup', 'picked_up', 'returned', 'missing', 'item_issue'])) {
                        $actorId = $req->custodian_id;
                    }
                } else {
                    if (str_contains($n->type, 'submitted') || str_contains($n->type, 'cancelled') || str_contains($n->type, 'return_initiated')) {
                        $actorId = $req->student_id;
                    } elseif (str_contains($n->type, 'approved') || str_contains($n->type, 'rejected')) {
                        $actorId = $req->instructor_id;
                    } elseif (str_contains($n->type, 'ready_for_pickup') || str_contains($n->type, 'picked_up') || str_contains($n->type, 'returned') || str_contains($n->type, 'missing') || str_contains($n->type, 'item_issue')) {
                        $actorId = $req->custodian_id;
                    }
                }
            }

            if (!$actorId) {
                $actorId = $n->user_id;
            }

            if ($actorId) {
                $notifActorIds[$n->id] = $actorId;
                $userIds->push($actorId);
            }
        }

        $users = User::whereIn('id', $userIds->unique())->get()->keyBy('id');

        foreach ($notifications as $n) {
            if ($n->type === 'support_message_received') {
                continue;
            }

            $actorId = $notifActorIds[$n->id] ?? null;
            if ($actorId && isset($users[$actorId])) {
                $user = $users[$actorId];
                $metadata = $n->metadata ?? [];
                $metadata['actorName'] = trim("{$user->first_name} {$user->last_name}");
                if ($user->profile_photo_url) {
                    $metadata['actorPhotoUrl'] = $user->profile_photo_url;
                }
                $n->metadata = $metadata;
            }
        }

        return $notifications;
    }
}
