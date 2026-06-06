<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'active',
        'message' => 'CHTM Cooks API Backend'
    ]);
});
