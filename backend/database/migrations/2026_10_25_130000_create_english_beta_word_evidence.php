<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('english_beta_word_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('english_beta_sessions')->cascadeOnDelete();
            $table->string('scene', 32);
            $table->string('word', 32);
            $table->string('dimension', 24);
            $table->string('source', 24);
            $table->timestamp('created_at');
            $table->index(['family_id', 'child_id', 'word', 'scene']);
            $table->index(['session_id', 'word']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_beta_word_evidence');
    }
};
