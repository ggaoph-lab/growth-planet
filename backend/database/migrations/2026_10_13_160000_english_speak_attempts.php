<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('english_speak_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('target_key', 64);
            $table->string('target_text', 120);
            $table->string('target_meaning', 80);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('mime', 64);
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('transcript', 255)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('feedback', 160)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('status', 16);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['child_id', 'business_date']);
            $table->index(['family_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_speak_attempts');
    }
};
