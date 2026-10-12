<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events plumbing shared by every realtime endpoint.
 *
 * Each connection watches a set of topics. Every tick it recomputes their
 * fingerprints (see ChangeSignatures) and emits an event for any that moved, so
 * a client learns about a change within one poll interval rather than waiting
 * out a refresh timer.
 *
 * The connection is deliberately short-lived. PHP-FPM dedicates a worker to an
 * open stream, so holding one open indefinitely trades away request capacity;
 * closing and letting the browser reconnect returns the worker to the pool.
 *
 * Reconnecting is lossless and cheap:
 *
 *  - `connected` carries the current fingerprint of every topic, so a client
 *    that already has a baseline refetches only what actually moved while it
 *    was away, instead of everything on every reconnect.
 *  - Every frame carries an `id` (the server time of that read). The browser
 *    echoes it back as `Last-Event-ID`, which lets the server re-announce
 *    anything written in the same second as the client's last read.
 */
class StreamService
{
    /** How often to re-read fingerprints, in seconds. */
    public const POLL_SECONDS = 2;

    /** How long one connection lives before handing its worker back. */
    public const DURATION_SECONDS = 30;

    /** Browser reconnect delay, in milliseconds. */
    public const RETRY_MS = 1000;

    /**
     * @param array<string, callable(?User): string> $topics  event name => fingerprint fn
     */
    public static function respond(
        array $topics,
        ?User $user = null,
        ?int $pollSeconds = null,
        ?int $durationSeconds = null
    ): StreamedResponse {
        $pollSeconds ??= max(1, (int) config('realtime.poll_seconds', self::POLL_SECONDS));
        $durationSeconds ??= max(0, (int) config('realtime.duration_seconds', self::DURATION_SECONDS));
        $resumeFrom = self::resumePoint(request()->header('Last-Event-ID'));

        return new StreamedResponse(function () use ($topics, $user, $pollSeconds, $durationSeconds, $resumeFrom) {
            // The stream outlives a normal request, so lift the execution cap.
            @set_time_limit($durationSeconds + 15);

            // Keep running through a disconnect so the stream slot is always
            // released; the loop checks for the disconnect itself.
            @ignore_user_abort(true);

            // Under PHPUnit the output buffers belong to the test harness.
            while (!app()->runningUnitTests() && ob_get_level() > 0) {
                ob_end_flush();
            }

            // Baseline. A client resuming with its own baseline compares the
            // two and refetches only the topics that differ.
            $snapshot = ChangeSignatures::snapshot($user, $resumeFrom, $topics);
            $signatures = $snapshot['signatures'];
            $lastRead = $snapshot['at'];

            $slot = self::canHoldOpenConnections() ? self::claimSlot($durationSeconds) : null;

            echo 'retry: ' . self::RETRY_MS . "\n";
            echo "id: {$lastRead}\n";
            echo "event: connected\n";
            echo 'data: ' . json_encode([
                'signatures' => $signatures,
                'recent' => $snapshot['recent'],
                'at' => $lastRead,
                // False tells the client to poll the signature endpoint rather
                // than reconnect in a loop to a stream that cannot stay open.
                'hold' => $slot !== null,
            ]) . "\n\n";
            flush();

            if ($slot === null) {
                return;
            }

            try {
                $deadline = time() + $durationSeconds;

                while (time() < $deadline) {
                    sleep($pollSeconds);

                    if (connection_aborted()) {
                        return;
                    }

                    $next = ChangeSignatures::snapshot($user, $lastRead, $topics);
                    $lastRead = $next['at'];
                    $emitted = false;

                    foreach ($next['signatures'] as $event => $current) {
                        $moved = $current !== ($signatures[$event] ?? null)
                            || in_array($event, $next['recent'], true);

                        $signatures[$event] = $current;

                        if ($moved) {
                            echo "id: {$lastRead}\n";
                            echo "event: {$event}\n";
                            echo 'data: ' . json_encode(['signature' => $current]) . "\n\n";
                            $emitted = true;
                        }
                    }

                    if (!$emitted) {
                        // Advances the client's Last-Event-ID without raising
                        // an event, and keeps intermediaries from timing out.
                        echo ": keepalive\n";
                        echo "id: {$lastRead}\n\n";
                    }

                    flush();
                }
            } finally {
                self::releaseSlot($slot);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The Unix time a reconnecting client last heard from us, taken from its
     * `Last-Event-ID`. Anything implausible is ignored rather than trusted.
     */
    public static function resumePoint(?string $lastEventId): ?int
    {
        if ($lastEventId === null || !ctype_digit($lastEventId)) {
            return null;
        }

        $at = (int) $lastEventId;
        $now = time();

        // Older than an hour is not a resume, and the future is not a time.
        return ($at > $now - 3600 && $at <= $now + 5) ? $at : null;
    }

    /**
     * Reserve one of the limited open-stream slots, or null when all are taken.
     *
     * Slots expire on their own shortly after a stream's maximum lifetime, so a
     * worker that dies mid-stream cannot leak one for good.
     */
    private static function claimSlot(int $durationSeconds): ?string
    {
        $max = (int) config('realtime.max_streams', 5);

        try {
            for ($i = 0; $i < $max; $i++) {
                $key = "realtime:stream-slot:{$i}";

                if (Cache::add($key, 1, $durationSeconds + 10)) {
                    return $key;
                }
            }
        } catch (\Throwable $e) {
            // No cache, no budget to enforce against — polling is the safe side.
            report($e);
        }

        return null;
    }

    private static function releaseSlot(string $slot): void
    {
        try {
            Cache::forget($slot);
        } catch (\Throwable $e) {
            // It expires on its own.
        }
    }

    /**
     * The PHP built-in server handles one request at a time unless extra
     * workers are configured, so a long-lived stream there would block the
     * whole app.
     */
    private static function canHoldOpenConnections(): bool
    {
        if (php_sapi_name() !== 'cli-server') {
            return true;
        }

        return function_exists('pcntl_fork')
            && getenv('PHP_CLI_SERVER_WORKERS')
            && intval(getenv('PHP_CLI_SERVER_WORKERS')) > 1;
    }
}
