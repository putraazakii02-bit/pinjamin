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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('rental_transactions');
            $table->foreignId('extension_id')->nullable();
            $table->foreignId('reported_by')->constrained('users');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'other_manual']);
            $table->enum('payment_type', ['rental_fee', 'extension_fee']);
            $table->decimal('amount', 12, 2);
            $table->string('proof_path', 255)->nullable();
            $table->text('payment_note')->nullable();
            $table->enum('payment_status', [
                'reported',
                'confirmed',
                'rejected',
            ]);
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['extension_id', 'transaction_id'],
                'payments_extension_transaction_foreign'
            )
                ->references(['id', 'transaction_id'])
                ->on('rental_extensions')
                ->restrictOnDelete();

            $table->index(['transaction_id', 'payment_status']);
            $table->index(['extension_id', 'transaction_id']);
        });

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT chk_payments_type_extension_match CHECK (
                (payment_type = 'rental_fee' AND extension_id IS NULL)
                OR
                (payment_type = 'extension_fee' AND extension_id IS NOT NULL)
            )"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
