<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Concurrent event streams
    |--------------------------------------------------------------------------
    |
    | An open event stream occupies a PHP-FPM worker for its whole lifetime, so
    | the number held open at once has to stay well below `pm.max_children`
    | (see docker/php-fpm-pool.conf) or ordinary requests queue behind them.
    | Clients beyond this budget are told to poll the signature endpoint
    | instead, which costs one short request every few seconds. They see the
    | same updates, a moment later.
    |
    */

    'max_streams' => (int) env('REALTIME_MAX_STREAMS', 5),

    /** Seconds between fingerprint reads inside an open stream. */
    'poll_seconds' => (int) env('REALTIME_POLL_SECONDS', 2),

    /** Seconds one stream lives before handing its worker back. */
    'duration_seconds' => (int) env('REALTIME_STREAM_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Housekeeping on read paths
    |--------------------------------------------------------------------------
    |
    | The expiry and overdue sweeps also run when a request list is read, as a
    | fallback for deployments without the scheduler. This is the least time
    | between two such runs; the list itself is never delayed by more than one.
    |
    */

    'sweep_interval_seconds' => (int) env('REQUEST_SWEEP_INTERVAL_SECONDS', 60),

];
