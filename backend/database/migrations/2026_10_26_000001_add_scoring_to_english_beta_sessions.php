<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('english_beta_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('score_status', 24)->nullable();
            $table->string('score_feedback', 255)->nullable();
            $table->unsignedSmallInteger('max_point_reward')->default(0);
            $table->unsignedSmallInteger('max_coin_reward')->default(0);
            $table->unsignedSmallInteger('point_reward')->default(0);
            $table->unsignedSmallInteger('coin_reward')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('english_beta_sessions', fn (Blueprint $table) => $table->dropColumn([
            'score', 'score_status', 'score_feedback', 'max_point_reward', 'max_coin_reward', 'point_reward', 'coin_reward',
        ]));
    }
};
