<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Models\RewardPolicy;
use Illuminate\Support\Facades\DB;

class RewardPolicySeeder
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['code' => 'task.complete', 'xp_amount' => 10, 'coin_amount' => 5, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 50],
            ['code' => 'habit.check_in', 'xp_amount' => 5, 'coin_amount' => 2, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 20],
            ['code' => 'parent.bonus', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => true, 'max_per_event' => 50],
            ['code' => 'movie.first_start', 'xp_amount' => 3, 'coin_amount' => 3, 'point_amount' => 0, 'requires_approval' => false, 'max_per_subject' => 1],
            ['code' => 'movie.completion', 'xp_amount' => 5, 'coin_amount' => 5, 'point_amount' => 0, 'requires_approval' => false, 'max_per_subject' => 1],
            ['code' => 'movie.viewing', 'xp_amount' => 5, 'coin_amount' => 5, 'point_amount' => 0, 'requires_approval' => false, 'max_per_subject' => 4],
            ['code' => 'movie.cross_movie', 'xp_amount' => 5, 'coin_amount' => 5, 'point_amount' => 0, 'requires_approval' => false, 'max_per_week' => 5],
            ['code' => 'english.practice', 'xp_amount' => 2, 'coin_amount' => 1, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'english.log', 'xp_amount' => 5, 'coin_amount' => 5, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'english.active_choice', 'xp_amount' => 0, 'coin_amount' => 3, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 6],
            ['code' => 'english_beta.session', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => false, 'max_per_subject' => 1],
            // Science encyclopedia: 1 XP + 1 point per article after CN+EN listen; daily section cap 10.
            ['code' => 'encyclopedia.read', 'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 1, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'poetry.read_aloud', 'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 2, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'poetry.recite', 'xp_amount' => 2, 'coin_amount' => 0, 'point_amount' => 5, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'poetry.review', 'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 3, 'requires_approval' => false, 'max_per_day' => 15],
            ['code' => 'poetry.challenge', 'xp_amount' => 1, 'coin_amount' => 0, 'point_amount' => 3, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'item.checklist.before_school', 'xp_amount' => 5, 'coin_amount' => 2, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 10],
            ['code' => 'item.checklist.after_school', 'xp_amount' => 5, 'coin_amount' => 2, 'point_amount' => 0, 'requires_approval' => false, 'max_per_day' => 10],
            // Games award points (virtual shop currency); amounts overridden per play.
            ['code' => 'game.schulte', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => false, 'max_per_event' => 3, 'max_per_day' => 6],
            ['code' => 'game.mirror', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => false, 'max_per_event' => 3, 'max_per_day' => 6],
            ['code' => 'game.sudoku', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => false, 'max_per_event' => 3, 'max_per_day' => 6],
            // 雅思星球 (Word Atlas): amounts and daily caps come from the ielts.* family settings, not from these rows.
            ['code' => 'ielts.session', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => false],
            ['code' => 'ielts.mastered', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 0, 'requires_approval' => false],
            ['code' => 'points.daily_allowance', 'xp_amount' => 0, 'coin_amount' => 0, 'point_amount' => 10, 'requires_approval' => false, 'max_per_event' => 100, 'max_per_day' => 100],
        ];
    }

    public function seedForFamily(int $familyId): void
    {
        foreach (self::defaults() as $row) {
            RewardPolicy::query()->firstOrCreate(
                ['family_id' => $familyId, 'code' => $row['code']],
                [
                    'xp_amount' => $row['xp_amount'],
                    'coin_amount' => $row['coin_amount'],
                    'point_amount' => $row['point_amount'] ?? 0,
                    'requires_approval' => $row['requires_approval'],
                    'max_per_event' => $row['max_per_event'] ?? null,
                    'max_per_day' => $row['max_per_day'] ?? null,
                    'max_per_week' => $row['max_per_week'] ?? null,
                    'max_per_subject' => $row['max_per_subject'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }

    /** Backfill all families (artisan command / one-off). */
    public function seedAllFamilies(): int
    {
        $count = 0;
        foreach (DB::table('families')->pluck('id') as $familyId) {
            $this->seedForFamily((int) $familyId);
            $count++;
        }

        return $count;
    }
}
