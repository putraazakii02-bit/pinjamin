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
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->primary()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('nim', 30);
            $table->string('university_name', 150);
            $table->string('study_program', 100);
            $table->string('whatsapp_number', 20);
            $table->string('profile_photo_path', 255)->nullable();
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])
                ->default('unverified');
            $table->enum('verification_method', ['campus_email', 'admin_review'])->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['university_name', 'nim']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
