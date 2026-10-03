<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return response()->json([
        'status' => 'active',
        'message' => 'CHTM Cooks API Backend'
    ]);
});

// Secure route to seed demo data (classes, students, requests, donations, walk-ins)
// Usage: /seed-demo?token=chtm_secure_seed_2026  (safe to rerun, never duplicates)
Route::get('/seed-demo', function () {
    if (request('token') !== 'chtm_secure_seed_2026') {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    try {
        set_time_limit(120);
        Artisan::call('db:seed', ['--class' => 'StudentClassSeeder', '--force' => true]);
        return response("<h2>Demo Data Seeded!</h2><pre>" . Artisan::output() . "</pre>", 200)
            ->header('Content-Type', 'text/html');
    } catch (\Throwable $e) {
        return response("<h2>Error during demo seeding:</h2><pre>" . $e->getMessage() . "</pre>", 500)
            ->header('Content-Type', 'text/html');
    }
})->withoutMiddleware(app('router')->getMiddlewareGroups()['web'] ?? []);
