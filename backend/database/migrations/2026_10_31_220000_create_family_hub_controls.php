<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->boolean('hub_approvals_enabled')->default(false);
        });
        Schema::table('rewards', function (Blueprint $table) {
            $table->unsignedSmallInteger('game_minutes')->nullable();
        });
        Schema::create('family_hub_game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained('trusted_devices')->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->string('request_key', 64);
            $table->json('approval_ids');
            $table->unsignedInteger('minutes');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
            $table->unique(['device_id', 'request_key']);
        });
        // Backfill only explicit minute values; leave ambiguous names unconfigured.
        foreach (DB::table('rewards')->where('kind', 'screen_time')->get() as $reward) {
            if (preg_match('/(\d{1,3})\s*(?:min(?:utes?)?|分钟)/iu', $reward->name, $match) && (int) $match[1] > 0 && (int) $match[1] <= 240) {
                DB::table('rewards')->where('id', $reward->id)->update(['game_minutes' => (int) $match[1]]);
            }
        }
        foreach (DB::table('reward_redemptions')->where('status', 'requested')->get() as $redemption) {
            $snapshot = json_decode($redemption->reward_snapshot, true);
            if (($snapshot['kind'] ?? '') === 'screen_time' && preg_match('/(\d{1,3})\s*(?:min(?:utes?)?|分钟)/iu', $snapshot['name'] ?? '', $match) && (int) $match[1] > 0 && (int) $match[1] <= 240) {
                $snapshot['game_minutes'] = (int) $match[1];
                DB::table('reward_redemptions')->where('id', $redemption->id)->update(['reward_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
            }
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('family_hub_game_sessions');
        Schema::table('rewards', fn (Blueprint $table) => $table->dropColumn('game_minutes'));
        Schema::table('trusted_devices', fn (Blueprint $table) => $table->dropColumn('hub_approvals_enabled'));
    }
};
