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
        Schema::create('rental_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('borrower_id')->constrained('users');
            $table->text('request_message')->nullable();
            $table->enum('status', [
                'pending',
                'negotiating',
                'accepted',
                'rejected',
                'cancelled',
                'expired',
            ])->default('pending');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(
                ['item_id', 'borrower_id', 'status'],
                'rental_requests_item_borrower_status_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_requests');
    }
};
