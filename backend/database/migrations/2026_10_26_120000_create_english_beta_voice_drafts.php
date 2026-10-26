<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('english_beta_voice_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('session_id')->constrained('english_beta_sessions')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->string('transcript', 240);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_beta_voice_drafts');
    }
};
