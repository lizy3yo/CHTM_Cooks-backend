<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Session\Middleware\StartSession;

Route::get('/', function () {
    return response()->json([
        'status' => 'active',
        'message' => 'CHTM Cooks API Backend'
    ]);
});

// Web route to run database seeders directly from browser without requiring sessions table
Route::get('/seed', function () {
    try {
        Artisan::call('db:seed', ['--force' => true]);
        return response("<h2>Database Seeded Successfully!</h2><pre>" . Artisan::output() . "</pre>", 200)
            ->header('Content-Type', 'text/html');
    } catch (\Throwable $e) {
        return response("<h2>Error during seeding:</h2><pre>" . $e->getMessage() . "</pre>", 500)
            ->header('Content-Type', 'text/html');
    }
})->withoutMiddleware([StartSession::class]);

// Web route to run migrations + seeders directly from browser without requiring sessions table
Route::get('/run-db-seed', function () {
    try {
        $output = "<h2>Running Database Setup & Seeding...</h2>";

        Artisan::call('migrate', ['--force' => true]);
        $output .= "<h3>Migrations Output:</h3><pre>" . Artisan::output() . "</pre>";

        Artisan::call('db:seed', ['--force' => true]);
        $output .= "<h3>Seeder Output:</h3><pre>" . Artisan::output() . "</pre>";

        $output .= "<h3 style='color: green;'>Database migration and seeding completed successfully!</h3>";

        return response($output, 200)->header('Content-Type', 'text/html');
    } catch (\Throwable $e) {
        return response("<h2>Error occurred:</h2><pre>" . $e->getMessage() . "</pre>", 500)
            ->header('Content-Type', 'text/html');
    }
})->withoutMiddleware([StartSession::class]);
