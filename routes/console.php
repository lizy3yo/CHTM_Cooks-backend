<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:clear', function () {
    $this->info('Starting inventory clearing process...');

    $items = \App\Models\InventoryItem::withTrashed()->get();
    $itemCount = $items->count();
    $this->info("Found {$itemCount} items to delete.");

    $deletedImages = 0;
    foreach ($items as $item) {
        if ($item->picture) {
            $this->line("Deleting image for item: {$item->name}...");
            try {
                \App\Services\StorageService::deleteByUrl($item->picture, 'inventory');
                $deletedImages++;
            } catch (\Exception $e) {
                $this->error("Failed to delete image: {$item->picture}. Error: " . $e->getMessage());
            }
        }
        $item->forceDelete();
    }

    // Reset category item counts to 0
    \App\Models\InventoryCategory::query()->update(['item_count' => 0]);
    $this->info("Category item counts reset to 0.");

    // Clear deleted items log
    \App\Models\DeletedInventoryItem::truncate();
    $this->info("Deleted inventory items logs cleared.");

    $this->info("Inventory cleared successfully. Deleted {$itemCount} items and {$deletedImages} Cloudinary image assets.");
})->purpose('Clear all inventory items and their corresponding Cloudinary image assets');

/*
 * Housekeeping that has to happen whether or not anyone is using the app.
 * Run by `php artisan schedule:work` (see docker/supervisord.conf). The same
 * sweeps also run on read paths, so a deployment without the scheduler still
 * catches up the next time a staff member opens the request list.
 */
Schedule::call(fn () => \App\Services\BorrowRequestExpiry::sweep())
    ->hourly()
    ->name('requests:expire-uncollected')
    ->withoutOverlapping();

Schedule::call(fn () => \App\Services\OverdueEnforcement::sweep())
    ->hourly()
    ->name('requests:overdue-notices')
    ->withoutOverlapping();

// Closes last month's inventory variance as soon as a new month begins.
Schedule::call(fn () => \App\Services\InventoryVariance::closeDue())
    ->hourly()
    ->name('inventory:close-month')
    ->withoutOverlapping();
