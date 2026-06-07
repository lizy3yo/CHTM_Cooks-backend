<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;
use App\Services\AiChatService;

class SupportTicketController extends Controller
{
    /**
     * @param \App\Models\SupportTicket|\stdClass $ticket
     * @return array
     */
    private function transformTicket($ticket)
    {
        $student = $ticket->student;
        
        return [
            'id' => (string) $ticket->id,
            'studentId' => (string) $ticket->student_id,
            'ownerRole' => $ticket->owner_role,
            'studentName' => $student ? $student->first_name . ' ' . $student->last_name : 'Unknown Student',
            'studentEmail' => $student ? $student->email : '',
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'lastMessageAt' => $ticket->last_message_at ? $ticket->last_message_at->toIso8601String() : null,
            'unreadBySuperadmin' => (int) $ticket->unread_by_superadmin,
            'unreadByStudent' => (int) $ticket->unread_by_student,
            'createdAt' => $ticket->created_at->toIso8601String(),
            'updatedAt' => $ticket->updated_at->toIso8601String(),
            'messages' => $ticket->messages->map(fn($m) => [
                'id' => (string) $m->id,
                'sender' => $m->sender,
                'senderId' => (string) $m->sender_id,
                'senderName' => $m->sender_name,
                'body' => $m->body,
                'sentAt' => $m->sent_at ? $m->sent_at->toIso8601String() : null
            ])->toArray()
        ];
    }

    public function getTickets(Request $request)
    {
        $user = auth()->user();
        $query = SupportTicket::query()->with(['student', 'messages']);

        // Scope by user
        if ($user->role !== 'superadmin') {
            $query->where('student_id', $user->id);
        }

        $tickets = $query->orderBy('last_message_at', 'desc')->get();

        return response()->json([
            'tickets' => $tickets->map(fn($t) => $this->transformTicket($t))
        ]);
    }

    public function createTicket(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'message' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $now = Carbon::now();

        $ticket = SupportTicket::create([
            'student_id' => $user->id,
            'owner_role' => $user->role,
            'subject' => $request->subject,
            'status' => 'open',
            'last_message_at' => $now,
            'unread_by_superadmin' => 1,
            'unread_by_student' => 0
        ]);

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender' => $user->role === 'superadmin' ? 'superadmin' : 'student',
            'sender_id' => $user->id,
            'sender_name' => $user->first_name . ' ' . $user->last_name,
            'body' => $request->message,
            'sent_at' => $now
        ]);

        return response()->json($this->transformTicket($ticket), 201);
    }

    public function updateTicket(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ticketId' => 'required|integer|exists:support_tickets,id',
            'markRead' => 'sometimes|boolean',
            'message' => 'sometimes|string',
            'status' => 'sometimes|string|in:open,in_progress,resolved,closed'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $ticket = SupportTicket::with(['student', 'messages'])->find($request->ticketId);
        $user = auth()->user();
        $now = Carbon::now();

        if ($request->boolean('markRead', false)) {
            if ($user->role === 'superadmin') {
                $ticket->unread_by_superadmin = 0;
            } else {
                $ticket->unread_by_student = 0;
            }
            $ticket->save();
        }

        if ($request->filled('status')) {
            $ticket->status = $request->status;
            $ticket->save();
        }

        if ($request->filled('message')) {
            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender' => $user->role === 'superadmin' ? 'superadmin' : 'student',
                'sender_id' => $user->id,
                'sender_name' => $user->first_name . ' ' . $user->last_name,
                'body' => $request->message,
                'sent_at' => $now
            ]);

            $ticket->last_message_at = $now;
            if ($user->role === 'superadmin') {
                $ticket->unread_by_student += 1;
            } else {
                $ticket->unread_by_superadmin += 1;
                // Auto transition back to open/in_progress on user message
                if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
                    $ticket->status = 'open';
                }
            }
            $ticket->save();
        }

        return response()->json($this->transformTicket($ticket));
    }

    public function aiReply(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ticketId' => 'required|integer|exists:support_tickets,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid ticket ID'], 400);
        }

        $ticket = SupportTicket::with(['student', 'messages'])->find($request->ticketId);
        
        // Ensure last message was not from superadmin
        $lastMsg = $ticket->messages->last();
        if (!$lastMsg || $lastMsg->sender === 'superadmin') {
            return response()->json([
                'success' => true,
                'message' => 'No AI reply needed',
                'ticket' => $this->transformTicket($ticket)
            ]);
        }

        $aiReplyText = null;
        $key = env('GEMINI_API_KEY');
        $model = env('GOOGLE_AI_MODEL', 'gemini-1.5-flash');

        if ($key) {
            try {
                $contents = [];
                $snapshot = null;
                if ($ticket->student_id && $ticket->owner_role) {
                    $snapshot = AiChatService::buildUserContextSnapshot($ticket->student_id, $ticket->owner_role);
                }
                $systemInstruction = AiChatService::getSystemInstruction($ticket->owner_role ?? 'student', $snapshot);
                
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['text' => "System Instructions: " . $systemInstruction]]
                ];
                $contents[] = [
                    'role' => 'model',
                    'parts' => [['text' => "Understood. I will act as ARIA and help the user with culinary laboratory borrow management."]]
                ];

                foreach ($ticket->messages as $msg) {
                    $role = ($msg->sender === 'superadmin' && $msg->sender_name === 'ARIA') ? 'model' : 'user';
                    $contents[] = [
                        'role' => $role,
                        'parts' => [['text' => $msg->sender_name . ": " . $msg->body]]
                    ];
                }

                $response = Http::timeout(15)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                    'contents' => $contents
                ]);

                if ($response->successful()) {
                    $aiReplyText = $response->json('candidates.0.content.parts.0.text');
                } else {
                    \Log::error('Gemini API failed in support reply: ' . $response->body());
                }
            } catch (\Exception $e) {
                \Log::error('Gemini API exception in support reply: ' . $e->getMessage());
            }
        }

        // Fallback response if AI is not set up or fails
        if (!$aiReplyText) {
            $userMsg = strtolower($lastMsg->body);
            if (str_contains($userMsg, 'borrow') || str_contains($userMsg, 'request')) {
                $aiReplyText = "To borrow equipment, head to the Equipment Catalog, add items to your request, select your class code and dates, and submit it. Your instructor will need to approve it first.";
            } elseif (str_contains($userMsg, 'obligation') || str_contains($userMsg, 'damage') || str_contains($userMsg, 'lost') || str_contains($userMsg, 'miss')) {
                $aiReplyText = "If you have lost or damaged equipment, a replacement obligation is issued. You will need to replace the items or coordinate with the custodian to resolve the balance.";
            } elseif (str_contains($userMsg, 'class') || str_contains($userMsg, 'code')) {
                $aiReplyText = "Class codes connect your requests to your courses. You can enroll in a class code under your profile or student home page using the code provided by your instructor.";
            } else {
                $aiReplyText = "Hi! I am ARIA, your AI support assistant. I have logged your message. If you'd like to speak with a human support agent directly, please click the 'Message a person' button above.";
            }
        }

        // Save AI reply message
        $now = Carbon::now();
        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender' => 'superadmin',
            'sender_id' => User::where('role', 'superadmin')->first()?->id ?? 1,
            'sender_name' => 'ARIA',
            'body' => $aiReplyText,
            'sent_at' => $now
        ]);

        $ticket->last_message_at = $now;
        $ticket->unread_by_student += 1;
        $ticket->save();

        return response()->json([
            'success' => true,
            'ticket' => $this->transformTicket($ticket)
        ]);
    }

    public function stream()
    {
        return new StreamedResponse(function () {
            echo "retry: 15000\n";
            echo "event: connected\n";
            echo "data: {}\n\n";
            ob_flush();
            flush();

            if (php_sapi_name() !== 'cli-server') {
                // Heartbeat
                $start = time();
                while (time() - $start < 30) {
                    echo ": keepalive\n\n";
                    ob_flush();
                    flush();
                    sleep(10);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
