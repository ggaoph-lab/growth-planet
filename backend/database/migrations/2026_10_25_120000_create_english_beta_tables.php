<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('english_beta_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->string('scene', 32)->default('supermarket');
            $table->string('provider', 16)->default('mock');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['family_id', 'child_id', 'started_at']);
        });
        Schema::create('english_beta_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('english_beta_sessions')->cascadeOnDelete();
            $table->string('speaker', 16);
            $table->text('text');
            $table->string('provider', 16)->default('mock');
            $table->timestamps();
            $table->index(['session_id', 'id']);
        });
        Schema::create('english_beta_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('english_beta_sessions')->cascadeOnDelete();
            $table->string('code', 32);
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'code']);
        });
        Schema::create('english_beta_vocabulary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->string('word', 32);
            $table->boolean('understood')->default(false);
            $table->boolean('spoken')->default(false);
            $table->boolean('used_in_sentence')->default(false);
            $table->timestamps();
            $table->unique(['child_id', 'word']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('english_beta_vocabulary');
        Schema::dropIfExists('english_beta_tasks');
        Schema::dropIfExists('english_beta_turns');
        Schema::dropIfExists('english_beta_sessions');
    }
};
