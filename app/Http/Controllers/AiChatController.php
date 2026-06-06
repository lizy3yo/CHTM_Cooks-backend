<?php

namespace App\Http\Controllers;

use App\Helpers\JwtHelper;
use App\Models\User;
use App\Services\AiChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatController extends Controller
{
    /**
     * POST /api/ai-chat
     *
     * Public endpoint — no authentication required.
     * When a valid Bearer token is present the response is personalised with
     * the authenticated user's role-specific context and live metrics.
     * Guests receive a general ARIA response with no account data.
     *
     * All expensive work (context building, Gemini request) is performed inside
     * the StreamedResponse callback so the SSE connection is established
     * immediately and the browser never stalls waiting for pre-flight DB queries.
     *
     * Request body:
     * {
     *   "messages": [
     *     { "role": "user",      "content": "..." },
     *     { "role": "assistant", "content": "..." }
     *   ]
     * }
     *
     * SSE stream format (OpenAI-compatible):
     * data: {"choices":[{"delta":{"content":"..."}}]}
     * …
     * data: [DONE]
     */
    public function chat(Request $request): StreamedResponse
    {
        // ── 1. Validate input ─────────────────────────────────────────────────
        // Validation runs synchronously before the stream opens so that
        // a 422 JSON error is returned correctly for malformed requests.
        $validated = $request->validate([
            'messages'           => ['required', 'array', 'min:1'],
            'messages.*.role'    => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:8000'],
        ]);

        $messages = $validated['messages'];

        // Capture the bearer token now (the Request object is not available
        // inside the StreamedResponse closure on some SAPI configurations).
        $bearerToken = $request->bearerToken();
        $apiKey      = config('services.gemini.api_key');
        $model       = config('services.gemini.model', 'gemini-2.0-flash');
        $connectMs   = 8000;   // max time to establish connection to Gemini (ms)

        // ── 2. Stream everything else inside the closure ──────────────────────
        // Opening the StreamedResponse immediately sends the HTTP 200 +
        // text/event-stream headers to the browser. Context building and the
        // Gemini request happen here so the client never blocks waiting for
        // pre-flight DB work.
        return new StreamedResponse(function () use ($messages, $bearerToken, $apiKey, $model, $connectMs) {
            // Disable PHP output buffering so every echo/flush reaches the
            // browser without waiting for a buffer threshold.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Send an SSE keepalive comment immediately.
            // This establishes the connection on the browser side and prevents
            // proxy / gateway timeouts during the context-build + Gemini TTFT window.
            echo ": keepalive\n\n";
            flush();

            // ── 2a. Resolve optional authenticated user ───────────────────────
            $user = $this->resolveOptionalUserFromToken($bearerToken);
            $role = $user?->role ?? 'guest';

            // ── 2b. Build lightweight context snapshot ────────────────────────
            // Uses simple COUNT queries only — avoids the full 180-day trust
            // score computation that runs in the student statistics dashboard.
            $contextSnapshot = null;
            if ($user) {
                try {
                    $contextSnapshot = AiChatService::buildLightUserContextSnapshot($user->id, $role);
                } catch (\Throwable $e) {
                    Log::warning('AiChat: Could not build user context snapshot', [
                        'userId' => $user->id,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            // ── 2c. Compile system instruction ────────────────────────────────
            $systemInstruction = AiChatService::getSystemInstruction($role, $contextSnapshot);

            // ── 2d. Map history → Gemini contents ────────────────────────────
            // Gemini roles: "user" and "model" (OpenAI's "assistant" → "model").
            $contents = array_map(fn (array $m) => [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ], $messages);

            $payload = [
                'system_instruction' => [
                    'parts' => [['text' => $systemInstruction]],
                ],
                'contents'         => $contents,
                'generationConfig' => [
                    'temperature'     => 0.7,
                    'topP'            => 0.95,
                    'maxOutputTokens' => 1024,
                ],
            ];

            $geminiUrl = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
                $model,
                $apiKey
            );

            // ── 2e. Stream Gemini SSE → client ────────────────────────────────
            $ch = curl_init($geminiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => json_encode($payload),
                CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
                // Connection timeout only — no total transfer timeout so long
                // streaming responses are never truncated mid-sentence.
                CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
                CURLOPT_RETURNTRANSFER    => false,
                CURLOPT_WRITEFUNCTION     => function ($curl, $chunk) {
                    // Gemini SSE line format:
                    // data: {"candidates":[{"content":{"parts":[{"text":"..."}],...}},...]}
                    //
                    // Re-emit as OpenAI-compatible delta so the frontend reader
                    // requires zero changes.
                    $lines = explode("\n", $chunk);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!str_starts_with($line, 'data:')) {
                            continue;
                        }

                        $raw = trim(substr($line, 5));

                        if ($raw === '[DONE]') {
                            echo "data: [DONE]\n\n";
                            flush();
                            break;
                        }

                        $decoded = json_decode($raw, true);
                        if (!$decoded) {
                            continue;
                        }

                        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
                        if ($text === null) {
                            continue;
                        }

                        echo 'data: ' . json_encode([
                            'choices' => [[
                                'delta' => ['content' => $text],
                            ]],
                        ]) . "\n\n";
                        flush();
                    }

                    return strlen($chunk);
                },
            ]);

            curl_exec($ch);

            $curlError = curl_errno($ch) ? curl_error($ch) : null;
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError || ($httpCode && $httpCode >= 400)) {
                Log::error('AiChat: Gemini streaming error', [
                    'curlError' => $curlError,
                    'httpCode'  => $httpCode,
                    'model'     => $model,
                ]);
                echo 'data: ' . json_encode([
                    'error' => 'AI service is temporarily unavailable. Please try again.',
                ]) . "\n\n";
                flush();
            }

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // Disable Nginx proxy buffering
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * Attempt to resolve the authenticated user from a raw Bearer token string
     * without throwing. Returns the User model when a valid token is present,
     * or null for guest / unauthenticated requests.
     *
     * @param string|null $token
     */
    private function resolveOptionalUserFromToken(?string $token): ?User
    {
        if (!$token) {
            return null;
        }

        try {
            $payload = JwtHelper::verify($token, env('JWT_SECRET'));
            if (!$payload) {
                return null;
            }

            $user = User::find($payload['userId']);
            return ($user && $user->is_active) ? $user : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
