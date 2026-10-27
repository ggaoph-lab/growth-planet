<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('poetry_poems', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('builtin'); // builtin | shiquan
            $table->string('external_id', 64)->nullable();
            $table->string('title', 128);
            $table->string('author', 64)->nullable();
            $table->string('dynasty', 32)->nullable();
            $table->text('content'); // lines joined by \n
            $table->string('grade_tag', 32)->nullable(); // e.g. grade1
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index(['title', 'author']);
        });

        Schema::create('poetry_poem_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->text('story_zh')->nullable();
            $table->text('image_prompt')->nullable();
            $table->string('ai_provider', 32)->nullable();
            $table->string('ai_model', 64)->nullable();
            $table->timestamps();
            $table->unique('poem_id');
        });

        Schema::create('poetry_image_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('model', 128)->nullable();
            $table->text('prompt');
            $table->text('image_url')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('status', 16)->default('ready'); // pending | ready | failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['poem_id', 'provider']);
            $table->index(['poem_id', 'status']);
        });

        Schema::create('poetry_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('active'); // active | done | cancelled
            $table->timestamps();
            $table->unique(['child_id', 'poem_id']);
            $table->index(['family_id', 'child_id', 'status']);
        });

        Schema::create('poetry_recitation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->string('kind', 24); // read_aloud | recite | review
            $table->text('transcript')->nullable();
            $table->boolean('passed')->default(false);
            $table->unsignedTinyInteger('accuracy_percent')->nullable();
            $table->json('diff')->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedSmallInteger('points_awarded')->default(0);
            $table->timestamps();
            $table->index(['child_id', 'poem_id', 'kind']);
            $table->index(['child_id', 'created_at']);
        });

        Schema::create('poetry_review_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->cascadeOnDelete();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->date('anchor_date'); // first successful recite date (family TZ business date)
            $table->unsignedTinyInteger('step_index')->default(0); // 0..n of intervals
            $table->date('next_review_date');
            $table->string('status', 16)->default('pending'); // pending | due | done | mastered
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['child_id', 'poem_id']);
            $table->index(['child_id', 'next_review_date', 'status']);
        });

        $seeder = app(\App\Modules\Ledger\Services\RewardPolicySeeder::class);
        foreach (DB::table('families')->pluck('id') as $familyId) {
            $seeder->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poetry_review_plans');
        Schema::dropIfExists('poetry_recitation_records');
        Schema::dropIfExists('poetry_assignments');
        Schema::dropIfExists('poetry_image_cache');
        Schema::dropIfExists('poetry_poem_enrichments');
        Schema::dropIfExists('poetry_poems');
    }
};
