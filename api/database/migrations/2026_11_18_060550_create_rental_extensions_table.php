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
        Schema::create('rental_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')
                ->constrained('rental_transactions')
                ->restrictOnDelete();
            $table->foreignId('proposer_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('previous_extension_id')
                ->nullable()
                ->constrained('rental_extensions')
                ->restrictOnDelete();
            $table->dateTime('previous_due_at');
            $table->dateTime('proposed_due_at');
            $table->decimal('proposed_fee', 12, 2)->default(0);
            $table->text('message')->nullable();
            $table->enum('status', [
                'pending',
                'accepted',
                'rejected',
                'withdrawn',
                'superseded',
            ])->default('pending');
            $table->foreignId('responded_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'transaction_id']);
            $table->index(['transaction_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_extensions');
    }
};
