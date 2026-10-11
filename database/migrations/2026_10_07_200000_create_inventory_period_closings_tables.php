<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Month-end closing of the inventory variance.
 *
 * Each closing fixes the count every item starts the next month with. Stock
 * gained during the month (donations, extra replacements) is absorbed into
 * that starting count, so the Over list begins empty again; units still with
 * borrowers or owed as replacements are carried forward until they come back.
 *
 * The per-item rows keep what each closing changed, so past months stay
 * viewable and a starting count can always be traced back.
 *
 * Plain columns only, so the same migration runs on PostgreSQL (hosted),
 * MySQL (local) and SQLite (tests). Guarded so a failed run can be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_period_closings')) {
            Schema::create('inventory_period_closings', function (Blueprint $table) {
                $table->id();
                // The month being closed, as yyyy-mm. One closing per month.
                $table->string('period', 7)->unique();
                $table->timestamp('closed_at');
                // Null when closed by the scheduler.
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                // The first closing resets every starting count; see InventoryVariance.
                $table->boolean('is_restart')->default(false);
                $table->unsignedInteger('items_count')->default(0);
                $table->integer('opening_total')->default(0);
                $table->integer('closing_total')->default(0);
                $table->integer('over_absorbed')->default(0);
                $table->integer('written_off')->default(0);
                $table->integer('under_carried')->default(0);
            });
        }

        if (!Schema::hasTable('inventory_period_closing_items')) {
            Schema::create('inventory_period_closing_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('closing_id')->constrained('inventory_period_closings')->cascadeOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
                $table->string('item_name');
                $table->string('category')->nullable();
                $table->integer('baseline_before')->default(0);
                $table->integer('on_hand')->default(0);
                $table->integer('out_on_loan')->default(0);
                $table->integer('owed')->default(0);
                $table->integer('over_absorbed')->default(0);
                $table->integer('written_off')->default(0);
                $table->integer('baseline_after')->default(0);

                $table->index('item_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_period_closing_items');
        Schema::dropIfExists('inventory_period_closings');
    }
};
