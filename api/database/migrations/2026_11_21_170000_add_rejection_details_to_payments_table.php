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
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('rejected_by')
                ->nullable()
                ->after('confirmed_at')
                ->constrained('users');
            $table->timestamp('rejected_at')
                ->nullable()
                ->after('rejected_by');
            $table->text('rejection_note')
                ->nullable()
                ->after('rejected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejection_note', 'rejected_at', 'rejected_by']);
        });
    }
};
