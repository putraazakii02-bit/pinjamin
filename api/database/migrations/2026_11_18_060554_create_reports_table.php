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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('reported_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('rental_transactions')
                ->restrictOnDelete();
            $table->foreignId('item_id')
                ->nullable()
                ->constrained('items')
                ->restrictOnDelete();
            $table->string('reason', 50);
            $table->text('description');
            $table->string('evidence_path', 255)->nullable();
            $table->enum('status', [
                'submitted',
                'under_review',
                'resolved',
                'rejected',
            ])->default('submitted');
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT chk_reports_has_target CHECK (
                reported_user_id IS NOT NULL
                OR transaction_id IS NOT NULL
                OR item_id IS NOT NULL
            )'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
