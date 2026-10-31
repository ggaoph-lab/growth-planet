<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_hub_game_sessions', function (Blueprint $table) {
            $table->boolean('extension_used')->default(false);
            $table->dateTime('ended_early_at')->nullable();
        });
        $previous = [];
        foreach (DB::table('family_hub_game_sessions')->orderBy('id')->get() as $session) {
            $prior = $previous[$session->device_id] ?? null;
            if ($prior && $prior->child_id === $session->child_id && $prior->ends_at > $session->starts_at) {
                DB::table('family_hub_game_sessions')->where('id', $session->id)->update(['extension_used' => true]);
            }
            $previous[$session->device_id] = $session;
        }
    }

    public function down(): void
    {
        Schema::table('family_hub_game_sessions', fn (Blueprint $table) => $table->dropColumn(['extension_used', 'ended_early_at']));
    }
};
