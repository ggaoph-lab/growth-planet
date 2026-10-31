<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('game_play_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->date('played_on');
            $table->string('game', 20);
            $table->string('level', 20);
            $table->unsignedTinyInteger('slot');
            $table->timestamp('finished_at', 3)->nullable();
            $table->timestamps(3);
            $table->unique(['child_id', 'played_on', 'game', 'level', 'slot'], 'game_play_daily_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_play_sessions');
    }
};
