<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ClassCodeController;
use App\Http\Controllers\BorrowRequestController;
use App\Http\Controllers\DonationAndObligationController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StudentStatisticsController;
use App\Http\Controllers\AnalyticsReportController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\CartController;

use Illuminate\Support\Facades\Artisan;

// Secure route to trigger migrations and seeding from the browser (Render Free Tier)
// Located in api.php to bypass session middleware (since the sessions table doesn't exist yet)
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

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/auto-login', [AuthController::class, 'autoLogin']);
    Route::get('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    
    // Protected auth/profile routes
    Route::middleware('jwt.auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::patch('/profile', [UserController::class, 'updateProfile']);
        Route::post('/profile/photo', [UserController::class, 'uploadProfilePhoto']);
        Route::delete('/profile/photo', [UserController::class, 'removeProfilePhoto']);
        Route::patch('/profile/password', [UserController::class, 'changePassword']);
        Route::get('/profile/stream', [UserController::class, 'profileStream']);
    });
});

Route::middleware('jwt.auth')->group(function () {
    // Inventory Catalog (unified read-only endpoint for borrow flow)
    Route::get('/inventory/catalog', [InventoryController::class, 'getCatalog']);

    // Inventory Categories
    Route::get('/inventory/categories', [InventoryController::class, 'getCategories']);
    Route::post('/inventory/categories', [InventoryController::class, 'createCategory']);
    Route::patch('/inventory/categories/{id}', [InventoryController::class, 'updateCategory']);
    Route::delete('/inventory/categories/{id}', [InventoryController::class, 'deleteCategory']);

    // Inventory Items
    Route::get('/inventory/items', [InventoryController::class, 'getItems']);
    Route::get('/inventory/items/{id}', [InventoryController::class, 'getItemById']);
    Route::post('/inventory/items', [InventoryController::class, 'createItem']);
    Route::post('/inventory/items/bulk', [InventoryController::class, 'bulkCreateItems']);
    Route::patch('/inventory/items/{id}', [InventoryController::class, 'updateItem']);
    Route::delete('/inventory/items/{id}', [InventoryController::class, 'deleteItem']);

    // Required Items
    Route::get('/inventory/required', [InventoryController::class, 'getRequiredItems']);
    Route::patch('/inventory/required', [InventoryController::class, 'bulkUpdateRequired']);

    // Archived Items
    Route::get('/inventory/archived', [InventoryController::class, 'getArchivedItems']);
    Route::post('/inventory/archived', [InventoryController::class, 'restoreArchivedItem']);

    // Deleted Items
    Route::get('/inventory/deleted', [InventoryController::class, 'getDeletedItems']);
    Route::post('/inventory/deleted', [InventoryController::class, 'restoreDeletedItem']);
    Route::delete('/inventory/deleted', [InventoryController::class, 'permanentlyDelete']);

    // Upload
    Route::post('/inventory/upload', [InventoryController::class, 'uploadImage']);

    // History logs
    Route::get('/inventory/history', [InventoryController::class, 'getHistory']);

    // Stream
    Route::get('/inventory/stream', [InventoryController::class, 'stream']);

    // Export
    Route::get('/inventory/export', [InventoryController::class, 'export']);

    // Borrowers tracking
    Route::get('/inventory/borrowers', [InventoryController::class, 'getAllBorrowers']);

    // Class Codes
    Route::get('/class-codes', [ClassCodeController::class, 'getAll']);
    Route::get('/class-codes/stats', [ClassCodeController::class, 'getStats']);
    Route::get('/class-codes/stream', [ClassCodeController::class, 'stream']);
    Route::get('/class-codes/my-classes', [ClassCodeController::class, 'getMyClasses']);
    Route::get('/class-codes/{id}', [ClassCodeController::class, 'getById']);
    Route::post('/class-codes', [ClassCodeController::class, 'create']);
    Route::patch('/class-codes/{id}', [ClassCodeController::class, 'update']);
    Route::delete('/class-codes/{id}', [ClassCodeController::class, 'delete']);
    Route::post('/class-codes/{id}/enrollments', [ClassCodeController::class, 'enrollStudents']);
    Route::delete('/class-codes/{id}/enrollments', [ClassCodeController::class, 'unenrollStudents']);

    // Borrow Requests
    Route::get('/borrow-requests', [BorrowRequestController::class, 'list']);
    Route::get('/borrow-requests/stream', [BorrowRequestController::class, 'stream']);
    Route::get('/borrow-requests/{id}', [BorrowRequestController::class, 'getById']);
    Route::post('/borrow-requests', [BorrowRequestController::class, 'create']);
    Route::post('/borrow-requests/{id}/approve', [BorrowRequestController::class, 'approve']);
    Route::post('/borrow-requests/{id}/reject', [BorrowRequestController::class, 'reject']);
    Route::delete('/borrow-requests/{id}', [BorrowRequestController::class, 'cancel']);
    Route::post('/borrow-requests/{id}/appeal', [BorrowRequestController::class, 'appeal']);
    Route::post('/borrow-requests/{id}/release', [BorrowRequestController::class, 'release']);
    Route::post('/borrow-requests/{id}/pickup', [BorrowRequestController::class, 'pickup']);
    Route::post('/borrow-requests/{id}/return', [BorrowRequestController::class, 'markReturned']);
    Route::post('/borrow-requests/{id}/missing', [BorrowRequestController::class, 'markMissing']);
    Route::post('/borrow-requests/{id}/send-reminder', [BorrowRequestController::class, 'sendOverdueReminder']);
    Route::post('/borrow-requests/{id}/inspect-items', [BorrowRequestController::class, 'inspectItems']);

    // Donations
    Route::get('/donations', [DonationAndObligationController::class, 'getDonations']);
    Route::get('/donations/stream', [DonationAndObligationController::class, 'streamDonations']);
    Route::get('/donations/{id}', [DonationAndObligationController::class, 'getDonationById']);
    Route::post('/donations', [DonationAndObligationController::class, 'createDonation']);
    Route::patch('/donations/{id}', [DonationAndObligationController::class, 'addDonationQuantity']);
    Route::put('/donations/{id}', [DonationAndObligationController::class, 'updateDonation']);
    Route::delete('/donations/{id}', [DonationAndObligationController::class, 'deleteDonation']);

    // Replacement Obligations
    Route::get('/replacement-obligations', [DonationAndObligationController::class, 'getObligations']);
    Route::get('/replacement-obligations/stream', [DonationAndObligationController::class, 'streamObligations']);
    Route::post('/replacement-obligations/reconcile', [DonationAndObligationController::class, 'reconcile']);
    Route::get('/replacement-obligations/{id}', [DonationAndObligationController::class, 'getObligationById']);
    Route::patch('/replacement-obligations/{id}', [DonationAndObligationController::class, 'resolveObligation']);

    // Support Tickets
    Route::get('/support', [SupportTicketController::class, 'getTickets']);
    Route::post('/support', [SupportTicketController::class, 'createTicket']);
    Route::patch('/support', [SupportTicketController::class, 'updateTicket']);
    Route::post('/support/ai-reply', [SupportTicketController::class, 'aiReply']);
    Route::get('/support/stream', [SupportTicketController::class, 'stream']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'getNotifications']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications', [NotificationController::class, 'markAllAsRead']);

    // Student Statistics
    Route::get('/student-statistics', [StudentStatisticsController::class, 'getStats']);
    Route::get('/dashboard/stats', [StudentStatisticsController::class, 'getDashboardStats']);

    // Analytics Reports
    Route::get('/reports/analytics', [AnalyticsReportController::class, 'getReport']);
    Route::get('/reports/analytics/summary', [AnalyticsReportController::class, 'getSummary']);
    Route::get('/reports/analytics/stream', [AnalyticsReportController::class, 'stream']);
    Route::get('/reports/analytics/export', [AnalyticsReportController::class, 'export']);

    // Users
    Route::get('/users', [UserController::class, 'getAll']);
    Route::get('/users/stream', [UserController::class, 'stream']);
    Route::get('/users/{id}', [UserController::class, 'getById']);
    Route::post('/users', [UserController::class, 'create']);
    Route::patch('/users', [UserController::class, 'update']);
    Route::delete('/users', [UserController::class, 'delete']);

    // Student Cart
    Route::get('/cart/stream', [CartController::class, 'stream']);
    Route::get('/cart', [CartController::class, 'getCart']);
    Route::post('/cart', [CartController::class, 'addItem']);
    Route::patch('/cart', [CartController::class, 'updateQuantity']);
    Route::delete('/cart', [CartController::class, 'deleteFromCart']);
});

/*
|--------------------------------------------------------------------------
| Public Routes (no authentication required)
|--------------------------------------------------------------------------
*/

// AI Chat (ARIA) — accessible to authenticated users and guests alike.
// Authentication is optional: the controller enriches the prompt with user
// context when a valid JWT is present, and falls back to guest mode otherwise.
Route::post('/ai-chat', [AiChatController::class, 'chat']);
