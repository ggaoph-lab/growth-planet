<?php

use App\Modules\Ledger\Services\RewardPolicySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schulte_daily_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->restrictOnDelete();
            $table->foreignId('child_id')->constrained('child_profiles')->restrictOnDelete();
            $table->date('played_on');
            $table->unsignedTinyInteger('size');
            $table->unsignedInteger('best_ms');
            $table->unsignedTinyInteger('coins_awarded')->default(0);
            $table->foreignId('reward_grant_id')->nullable()->constrained('reward_grants')->nullOnDelete();
            $table->timestamps(3);

            $table->unique(['child_id', 'played_on', 'size']);
            $table->index(['family_id', 'played_on', 'size']);
        });

        $seeder = app(RewardPolicySeeder::class);
        foreach (DB::table('families')->pluck('id') as $familyId) {
            $seeder->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schulte_daily_scores');
        DB::table('reward_policies')->where('code', 'game.schulte')->delete();
    }
};
