<?php

namespace App\Http\Controllers;

use App\Services\ChangeSignatures;
use App\Services\StreamService;

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
     * server, or a proxy that buffers event streams.
     */
    public function signature()
    {
        $user = auth()->user();
        $signatures = [];

        foreach (ChangeSignatures::forUser($user) as $event => $fingerprint) {
            try {
                $signatures[$event] = (string) $fingerprint($user);
            } catch (\Throwable $e) {
                $signatures[$event] = 'error';
            }
        }

        return response()->json(['signatures' => $signatures]);
    }
}
