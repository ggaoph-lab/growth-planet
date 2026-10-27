<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('poetry_audio_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poem_id')->constrained('poetry_poems')->cascadeOnDelete();
            $table->string('clip_key', 64); // full | line-0 | story | meaning-0
            $table->string('provider', 32);
            $table->string('voice', 128)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('script_hash', 64);
            $table->text('script');
            $table->string('disk', 32)->default('public');
            $table->string('path', 512);
            $table->string('mime', 64)->default('audio/mpeg');
            $table->unsignedInteger('bytes')->default(0);
            $table->string('status', 16)->default('ready'); // pending | ready | failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['poem_id', 'clip_key']);
            $table->index(['poem_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poetry_audio_cache');
    }
};
