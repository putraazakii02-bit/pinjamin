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
        Schema::create('rental_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')
                ->unique()
                ->constrained('rental_requests');
            $table->foreignId('accepted_negotiation_id')->unique();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('borrower_id')->constrained('users');
            $table->decimal('agreed_daily_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->dateTime('start_at');
            $table->dateTime('due_at');
            $table->timestamp('overdue_at')->nullable();
            $table->timestamp('owner_handover_confirmed_at')->nullable();
            $table->timestamp('borrower_received_confirmed_at')->nullable();
            $table->timestamp('borrower_returned_at')->nullable();
            $table->timestamp('owner_return_received_at')->nullable();
            $table->enum('status', [
                'awaiting_payment',
                'awaiting_payment_confirmation',
                'awaiting_handover',
                'active',
                'overdue',
                'return_pending_confirmation',
                'completed',
                'cancelled',
                'disputed',
            ])->default('awaiting_payment');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['accepted_negotiation_id', 'request_id'],
                'rental_transactions_accepted_negotiation_request_foreign'
            )
                ->references(['id', 'request_id'])
                ->on('rental_negotiations')
                ->restrictOnDelete();

            $table->index(['accepted_negotiation_id', 'request_id']);
            $table->index(['owner_id', 'borrower_id', 'status']);
            $table->index(['due_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_transactions');
    }
};
