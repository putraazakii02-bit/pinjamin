<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description');
            $table->decimal('daily_price', 12, 2);
            $table->enum('item_condition', ['new', 'like_new', 'good', 'fair']);
            $table->text('condition_notes')->nullable();
            $table->enum('listing_status', ['active', 'inactive', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
            $table->index('category_id');
            $table->index('listing_status');
        });

        DB::statement(
            'ALTER TABLE items ADD CONSTRAINT chk_items_daily_price CHECK (daily_price > 0)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
