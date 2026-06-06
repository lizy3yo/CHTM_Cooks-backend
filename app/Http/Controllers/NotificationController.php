<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notification;
use App\Services\NotificationService;
use Carbon\Carbon;

class NotificationController extends Controller
{
    /**
     * @param \App\Models\Notification $n
     * @return array
     */
    private function transformNotification($n)
    {
        return [
            'id' => (string) $n->id,
            'userId' => (string) $n->user_id,
            'audienceRole' => $n->audience_role,
            'type' => $n->type,
            'title' => $n->title,
            'message' => $n->message,
            'link' => $n->link,
            'borrowRequestId' => $n->borrow_request_id ? (string) $n->borrow_request_id : null,
            'metadata' => $n->metadata,
            'isRead' => (bool) $n->is_read,
            'readAt' => $n->read_at ? $n->read_at->toIso8601String() : null,
            'createdAt' => $n->created_at->toIso8601String(),
            'updatedAt' => $n->updated_at->toIso8601String()
        ];
    }

    public function getNotifications(Request $request)
    {
        $user = auth()->user();
        $limit = $request->integer('limit', 25);
        $skip = $request->integer('skip', 0);

        $query = Notification::where('user_id', $user->id);

        $unreadCount = (clone $query)->where('is_read', false)->count();

        $notifications = $query->orderBy('created_at', 'desc')
                              ->skip($skip)
                              ->take($limit)
                              ->get();

        $notifications = NotificationService::enrichNotifications($notifications);

        return response()->json([
            'notifications' => $notifications->map(fn($n) => $this->transformNotification($n)),
            'unreadCount' => $unreadCount
        ]);
    }

    public function markAsRead($id)
    {
        $user = auth()->user();
        $n = Notification::where('id', $id)->where('user_id', $user->id)->first();
        if (!$n) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $n->is_read = true;
        $n->read_at = Carbon::now();
        $n->save();

        return response()->json(['success' => true]);
    }

    public function markAllAsRead()
    {
        $user = auth()->user();

        $markedCount = Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Carbon::now()
            ]);

        return response()->json([
            'success' => true,
            'markedCount' => $markedCount
        ]);
    }
}
