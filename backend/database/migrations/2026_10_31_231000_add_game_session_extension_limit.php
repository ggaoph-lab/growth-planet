<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_hub_game_sessions', function (Blueprint $table) {
            $table->boolean('extension_used')->default(false);
            $table->dateTime('ended_early_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('family_hub_game_sessions', fn (Blueprint $table) => $table->dropColumn(['extension_used', 'ended_early_at']));
    }
};
