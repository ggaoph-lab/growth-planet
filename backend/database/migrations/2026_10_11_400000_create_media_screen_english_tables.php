<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->string('external_id', 64)->nullable();
            $table->string('title_zh', 255);
            $table->string('original_title', 255)->nullable();
            $table->unsignedBigInteger('poster_attachment_id')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedSmallInteger('runtime_minutes')->nullable();
            $table->json('genres')->nullable();
            $table->text('overview')->nullable();
            $table->json('certifications')->nullable();
            $table->boolean('tmdb_adult')->default(false);
            $table->string('data_completeness', 32)->default('insufficient');
            $table->string('attribution', 255)->nullable();
            $table->unsignedBigInteger('current_review_id')->nullable();
            $table->timestamps(3);

            $table->unique(['family_id', 'source', 'external_id']);
            $table->index(['family_id', 'title_zh']);
        });

        Schema::create('movie_age_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->restrictOnDelete();
            $table->string('safety_status', 32);
            $table->unsignedTinyInteger('min_age');
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->string('reason', 512)->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at', 3)->useCurrent();
        });

        Schema::table('movies', function (Blueprint $table) {
            $table->foreign('current_review_id')->references('id')->on('movie_age_reviews')->nullOnDelete();
        });

        Schema::create('movie_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->boolean('visible')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->timestamps(3);

            $table->unique(['movie_id', 'child_id']);
        });

        Schema::create('movie_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('movie_id')->nullable()->constrained()->nullOnDelete();
            $table->string('free_text', 512)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->timestamps(3);
        });

        Schema::create('child_movie_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('movie_id')->constrained()->restrictOnDelete();
            $table->string('shelf', 32)->default('want');
            $table->string('slot', 32)->nullable();
            $table->string('slot_active_key', 64)->nullable();
            $table->boolean('ever_completed')->default(false);
            $table->string('platform', 64)->nullable();
            $table->timestamps(3);

            $table->unique(['child_id', 'movie_id']);
            $table->unique('slot_active_key');
        });

        Schema::create('movie_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('library_id')->primary();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 32)->default('NOT_STARTED');
            $table->dateTime('first_started_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('last_updated_at', 3)->nullable();
            $table->unsignedInteger('lock_version')->default(0);

            $table->foreign('library_id')->references('id')->on('child_movie_library')->cascadeOnDelete();
        });

        Schema::create('movie_watch_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('child_movie_library')->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->unsignedInteger('measured_seconds')->default(0);
            $table->unsignedInteger('counted_seconds')->default(0);
            $table->string('entry_method', 16)->default('timer');
            $table->unsignedInteger('position_start')->default(0);
            $table->unsignedInteger('position_end')->nullable();
            $table->string('anomaly', 16)->default('none');
            $table->string('confirmation', 16)->default('confirmed');
            $table->timestamps(3);
        });

        Schema::create('movie_reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('library_id')->primary();
            $table->string('liking', 16);
            $table->string('favorite_character', 128)->nullable();
            $table->string('favorite_scene', 255)->nullable();
            $table->string('one_sentence', 512)->nullable();
            $table->boolean('want_similar')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->foreign('library_id')->references('id')->on('child_movie_library')->cascadeOnDelete();
        });

        Schema::create('movie_reward_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('library_id')->constrained('child_movie_library')->restrictOnDelete();
            $table->string('kind', 32);
            $table->dateTime('occurred_at', 3);
            $table->date('week_start')->nullable();
            $table->unsignedBigInteger('previous_library_id')->nullable();
            $table->unsignedBigInteger('reward_grant_id')->nullable();
            $table->timestamps(3);

            $table->unique(['library_id', 'kind']);
        });

        Schema::create('screen_time_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('category', 32);
            $table->string('period', 16);
            $table->unsignedInteger('minutes');
            $table->unsignedSmallInteger('warn_before_minutes')->default(15);
            $table->boolean('carry_over')->default(false);
            $table->date('effective_from');
            $table->timestamps(3);

            $table->unique(['child_id', 'category', 'period']);
        });

        Schema::create('screen_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('category', 32);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->date('period_start');
            $table->unsignedInteger('seconds');
            $table->string('entry_method', 16)->default('auto');
            $table->string('status', 32)->default('counted');
            $table->timestamps(3);

            $table->unique(['source_type', 'source_id']);
            $table->index(['child_id', 'category', 'period_start']);
        });

        Schema::create('screen_time_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('category', 32);
            $table->date('period_start');
            $table->string('kind', 32);
            $table->integer('seconds');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at', 3)->useCurrent();
        });

        Schema::create('english_shows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('title', 128);
            $table->string('emoji', 16)->default('📺');
            $table->string('note', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps(3);
            $table->softDeletes();

            $table->index(['family_id', 'active', 'sort']);
        });

        Schema::create('english_watch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->foreignId('show_id')->constrained('english_shows')->restrictOnDelete();
            $table->date('watch_date');
            $table->unsignedSmallInteger('minutes');
            $table->boolean('self_chosen')->default(false);
            $table->string('liking', 16)->nullable();
            $table->json('words_learned')->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at', 3)->nullable();
            $table->string('parent_note', 255)->nullable();
            $table->unsignedSmallInteger('approved_minutes')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['child_id', 'watch_date', 'status']);
        });

        $policySeeder = app(\App\Modules\Ledger\Services\RewardPolicySeeder::class);
        foreach (\Illuminate\Support\Facades\DB::table('families')->pluck('id') as $familyId) {
            $policySeeder->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('english_watch_logs');
        Schema::dropIfExists('english_shows');
        Schema::dropIfExists('screen_time_adjustments');
        Schema::dropIfExists('screen_time_entries');
        Schema::dropIfExists('screen_time_policies');
        Schema::dropIfExists('movie_reward_events');
        Schema::dropIfExists('movie_reviews');
        Schema::dropIfExists('movie_watch_sessions');
        Schema::dropIfExists('movie_progress');
        Schema::dropIfExists('child_movie_library');
        Schema::dropIfExists('movie_requests');
        Schema::dropIfExists('movie_approvals');
        Schema::table('movies', function (Blueprint $table) {
            $table->dropForeign(['current_review_id']);
        });
        Schema::dropIfExists('movie_age_reviews');
        Schema::dropIfExists('movies');
    }
};
