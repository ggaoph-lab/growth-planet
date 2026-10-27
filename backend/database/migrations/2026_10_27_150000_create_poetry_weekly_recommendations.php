<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('poetry_weekly_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->string('week_key', 16); // e.g. 2026-W44
            $table->unsignedTinyInteger('age_years')->nullable();
            $table->unsignedTinyInteger('grade')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('status', 16)->default('active'); // active | done | skipped
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['child_id', 'week_key']);
            $table->index(['child_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poetry_weekly_recommendations');
    }
};
