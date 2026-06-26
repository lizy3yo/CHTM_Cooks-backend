<?php

use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return response()->json([
        'status' => 'active',
        'message' => 'CHTM Cooks API Backend'
    ]);
});

// Secure route to trigger migrations and seeding from the browser (Render Free Tier)
Route::get('/run-db-seed', function () {
    if (request('token') !== 'chtm_secure_seed_2026') {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    try {
        $output = "";
        
        $output .= "Running migrations...<br>";
        Artisan::call('migrate', ['--force' => true]);
        $output .= Artisan::output() . "<br><br>";
        
        $output .= "Running seeders...<br>";
        Artisan::call('db:seed', ['--force' => true]);
        $output .= Artisan::output() . "<br><br>";
        
        return $output . "Database setup completed successfully!";
    } catch (\Exception $e) {
        return "Error occurred: " . $e->getMessage();
    }
});
