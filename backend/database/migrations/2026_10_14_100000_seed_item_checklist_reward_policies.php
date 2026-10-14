<?php

use App\Modules\Ledger\Services\RewardPolicySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(RewardPolicySeeder::class);
        foreach (DB::table('families')->pluck('id') as $familyId) {
            $seeder->seedForFamily((int) $familyId);
        }
    }

    public function down(): void
    {
        DB::table('reward_policies')
            ->whereIn('code', [
                'item.checklist.before_school',
                'item.checklist.after_school',
            ])
            ->delete();
    }
};
