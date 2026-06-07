<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_carts', function (Blueprint $table) {
            $table->id();

            // Owner — deleting a user cascades to remove their cart rows
            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Snapshot of inventory item metadata at the time of add.
            // Stored inline so the cart survives item archival or deletion.
            $table->string('item_id');
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('max_quantity');
            $table->string('category_id')->nullable();
            $table->string('picture')->nullable();

            // Separate insertion timestamp so the cart preserves add-order
            // independently of Laravel's auto-managed updated_at.
            $table->dateTime('added_at');
            $table->dateTime('updated_at');

            // One row per (student × item) — duplicates are merged by the controller
            $table->unique(['user_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_carts');
    }
};
