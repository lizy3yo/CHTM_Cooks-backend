<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock is a physical count and cannot be negative.
 *
 * pickup() previously decremented `donations` with no floor, so handing over
 * more than the records showed left items sitting below zero — a silently wrong
 * total that flows straight into inventory reports and exports.
 *
 * Units that cannot be accounted for are now recorded here instead, so the
 * shortfall stays visible and a custodian can reconcile it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->integer('stock_discrepancy')
                ->default(0)
                ->after('donations')
                ->comment('Units handed over that stock could not account for; awaiting reconciliation');
        });

        // Carry existing negative balances over as discrepancies, then floor the
        // counts. A -1 means one unit left the storeroom unaccounted for, which
        // is precisely what this column is meant to hold.
        DB::statement('UPDATE inventory_items
            SET stock_discrepancy = stock_discrepancy
                + GREATEST(0, -quantity)
                + GREATEST(0, -donations)
            WHERE quantity < 0 OR donations < 0');

        DB::statement('UPDATE inventory_items SET quantity = 0 WHERE quantity < 0');
        DB::statement('UPDATE inventory_items SET donations = 0 WHERE donations < 0');
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('stock_discrepancy');
        });
    }
};
