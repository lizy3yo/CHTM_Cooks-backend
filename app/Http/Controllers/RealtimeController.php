<?php

namespace App\Http\Controllers;

use App\Services\ChangeSignatures;
use App\Services\StreamService;
use Illuminate\Http\Request;

/**
 * One realtime stream per client, carrying every topic that client cares about.
 *
 * The per-domain stream endpoints still exist for compatibility, but a page that
 * watched three domains used to hold three PHP-FPM workers open at once. This
 * endpoint multiplexes them onto a single connection, which is what makes the
 * realtime layer survivable when a whole class is logged in.
 */
class RealtimeController extends Controller
{
    /**
     * GET /api/stream
     */
    public function stream()
    {
        $user = auth()->user();

        return StreamService::respond(ChangeSignatures::forUser($user), $user);
    }

    /**
     * GET /api/stream/signature
     *
     * The same fingerprints as a single JSON read. Used as a polling fallback
     * where a long-lived connection is not possible — a single-worker dev
     * server, a proxy that buffers event streams, or a server whose stream
     * budget is spent.
     *
     * `after` is the `at` value from the caller's previous read. It lets the
     * server report topics written in that same second (`recent`), which the
     * fingerprints alone cannot distinguish.
     */
    public function signature(Request $request)
    {
        $after = $request->query('after');
        $after = StreamService::resumePoint(is_string($after) ? $after : null);

        return response()->json(ChangeSignatures::snapshot(auth()->user(), $after));
    }
}
