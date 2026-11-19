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
        Schema::create('rental_negotiations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('rental_requests');
            $table->foreignId('proposer_id')->constrained('users');
            $table->decimal('proposed_daily_price', 12, 2);
            $table->dateTime('proposed_start_at');
            $table->dateTime('proposed_due_at');
            $table->text('message')->nullable();
            $table->enum('status', [
                'pending',
                'accepted',
                'rejected',
                'withdrawn',
                'superseded',
            ])->default('pending');
            $table->foreignId('responded_by')->nullable()->constrained('users');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'request_id']);
            $table->index(['request_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_negotiations');
    }
};
