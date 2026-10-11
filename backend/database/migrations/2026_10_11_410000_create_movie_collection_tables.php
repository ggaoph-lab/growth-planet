<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->string('external_id', 64)->nullable();
            $table->string('name', 191);
            $table->text('overview')->nullable();
            $table->unsignedBigInteger('poster_attachment_id')->nullable();
            $table->string('sort_mode', 16)->default('story');
            $table->unsignedSmallInteger('movie_count')->default(0);
            $table->boolean('structure_locked')->default(false);
            $table->boolean('name_locked')->default(false);
            $table->boolean('overview_locked')->default(false);
            $table->boolean('poster_locked')->default(false);
            $table->json('tmdb_snapshot')->nullable();
            $table->dateTime('tmdb_synced_at', 3)->nullable();
            $table->timestamps(3);
            $table->softDeletes();

            $table->unique(['family_id', 'source', 'external_id']);
            $table->index(['family_id', 'name']);
        });

        Schema::create('movie_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_id')->constrained('movie_collections')->cascadeOnDelete();
            $table->foreignId('movie_id')->constrained('movies')->restrictOnDelete();
            $table->integer('position_story')->nullable();
            $table->integer('position_release')->nullable();
            $table->integer('position_custom')->nullable();
            $table->string('membership_source', 16)->default('parent');
            $table->timestamps(3);

            $table->unique('movie_id');
            $table->unique(['collection_id', 'movie_id']);
            $table->index(['collection_id', 'position_story']);
            $table->index(['collection_id', 'position_release']);
            $table->index(['collection_id', 'position_custom']);
        });

        Schema::create('movie_collection_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->nullable()->constrained('child_profiles')->nullOnDelete();
            $table->foreignId('collection_id')->constrained('movie_collections')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('note', 255)->nullable();
            $table->timestamps(3);

            $table->unique(['collection_id', 'child_id', 'kind']);
            $table->index(['family_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_collection_preferences');
        Schema::dropIfExists('movie_collection_items');
        Schema::dropIfExists('movie_collections');
    }
};
