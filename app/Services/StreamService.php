<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events plumbing shared by every realtime endpoint.
 *
 * Each connection watches a set of topics. Every tick it recomputes their
 * fingerprints (see ChangeSignatures) and emits an event for any that moved, so
 * a client learns about a change within one poll interval rather than waiting
 * out a 30-second refresh timer.
 *
 * The connection is deliberately short-lived. PHP-FPM dedicates a worker to an
 * open stream, so holding one open indefinitely trades away request capacity;
 * closing and letting the browser reconnect returns the worker to the pool.
 * `retry` is set low because the client refetches on every (re)connect, which
 * makes the reconnect gap self-healing — no event IDs or replay buffer needed.
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
        int $pollSeconds = self::POLL_SECONDS,
        int $durationSeconds = self::DURATION_SECONDS
    ): StreamedResponse {
        return new StreamedResponse(function () use ($topics, $user, $pollSeconds, $durationSeconds) {
            // The stream outlives a normal request, so lift the execution cap.
            @set_time_limit($durationSeconds + 15);
            @ignore_user_abort(false);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo 'retry: ' . self::RETRY_MS . "\n";
            echo "event: connected\n";
            echo "data: {}\n\n";
            flush();

            // The PHP built-in server handles one request at a time unless extra
            // workers are configured, so a long-lived stream there would block
            // the whole app. Connect, then get out of the way.
            if (!self::canHoldOpenConnections()) {
                return;
            }

            // Baseline: only changes from this moment on are news. The client
            // refetches on connect anyway, so nothing before now is missed.
            $signatures = [];
            foreach ($topics as $event => $fingerprint) {
                $signatures[$event] = self::safeFingerprint($fingerprint, $user);
            }

            $deadline = time() + $durationSeconds;

            while (time() < $deadline) {
                sleep($pollSeconds);

                if (connection_aborted()) {
                    return;
                }

                $emitted = false;

                foreach ($topics as $event => $fingerprint) {
                    $current = self::safeFingerprint($fingerprint, $user);

                    if ($current !== $signatures[$event]) {
                        $signatures[$event] = $current;
                        echo "event: {$event}\n";
                        echo "data: {}\n\n";
                        $emitted = true;
                    }
                }

                if (!$emitted) {
                    echo ": keepalive\n\n";
                }

                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A dropped database connection should end the stream quietly and let the
     * client reconnect, never surface as a broken SSE frame mid-payload.
     */
    private static function safeFingerprint(callable $fingerprint, ?User $user): string
    {
        try {
            return (string) $fingerprint($user);
        } catch (\Throwable $e) {
            return 'error';
        }
    }

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
