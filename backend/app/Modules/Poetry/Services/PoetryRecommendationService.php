<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Models\PoetryRecitationRecord;
use App\Modules\Poetry\Models\PoetryWeeklyRecommendation;
use Carbon\CarbonImmutable;

/**
 * One poem per ISO week per child. Picks by age/grade, avoids repeats, records history.
 */
class PoetryRecommendationService
{
    public function __construct(
        private readonly PoetryCatalog $catalog,
        private readonly Clock $clock,
    ) {}

    public function weekKey(CarbonImmutable $businessDate): string
    {
        return $businessDate->isoWeekYear().'-W'.str_pad((string) $businessDate->isoWeek(), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Ensure this week's recommendation exists and return it with poem.
     */
    public function currentFor(ChildProfile $child, CarbonImmutable $businessDate): ?PoetryWeeklyRecommendation
    {
        $this->catalog->ensureBuiltinGrade1();
        $weekKey = $this->weekKey($businessDate);

        $existing = PoetryWeeklyRecommendation::query()
            ->with('poem')
            ->where('child_id', $child->id)
            ->where('week_key', $weekKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $pick = $this->pickPoem($child, $businessDate);
        if ($pick === null) {
            return null;
        }

        $age = $this->ageYears($child, $businessDate);

        return PoetryWeeklyRecommendation::query()->create([
            'family_id' => $child->family_id,
            'child_id' => $child->id,
            'poem_id' => $pick['poem']->id,
            'week_key' => $weekKey,
            'age_years' => $age,
            'grade' => (int) $child->grade,
            'reason' => $pick['reason'],
            'status' => 'active',
        ])->load('poem');
    }

    /**
     * @return list<PoetryWeeklyRecommendation>
     */
    public function historyFor(ChildProfile $child, int $limit = 12): array
    {
        return PoetryWeeklyRecommendation::query()
            ->with('poem')
            ->where('child_id', $child->id)
            ->orderByDesc('week_key')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function markDone(ChildProfile $child, int $poemId): void
    {
        PoetryWeeklyRecommendation::query()
            ->where('child_id', $child->id)
            ->where('poem_id', $poemId)
            ->where('status', 'active')
            ->update([
                'status' => 'done',
                'completed_at' => now(),
            ]);
    }

    /**
     * @return array{poem: PoetryPoem, reason: string}|null
     */
    private function pickPoem(ChildProfile $child, CarbonImmutable $businessDate): ?array
    {
        $usedIds = PoetryWeeklyRecommendation::query()
            ->where('child_id', $child->id)
            ->pluck('poem_id')
            ->all();

        $passedIds = PoetryRecitationRecord::query()
            ->where('child_id', $child->id)
            ->where('kind', 'recite')
            ->where('passed', true)
            ->pluck('poem_id')
            ->unique()
            ->all();

        $age = $this->ageYears($child, $businessDate) ?? 7;
        $grade = max(1, (int) $child->grade);

        $candidates = PoetryPoem::query()
            ->where('grade_tag', 'grade1')
            ->when($usedIds !== [], fn ($q) => $q->whereNotIn('id', $usedIds))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            // All grade1 already recommended — allow non-passed again, still avoid this week's pool emptiness.
            $candidates = PoetryPoem::query()
                ->where('grade_tag', 'grade1')
                ->when($passedIds !== [], fn ($q) => $q->whereNotIn('id', $passedIds))
                ->orderBy('id')
                ->get();
        }

        if ($candidates->isEmpty()) {
            $candidates = PoetryPoem::query()->where('grade_tag', 'grade1')->orderBy('id')->get();
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        // Younger kids: prefer fewer characters; older / higher grade: allow longer.
        $maxChars = match (true) {
            $age <= 6 || $grade <= 1 => 40,
            $age <= 8 || $grade <= 2 => 70,
            default => 120,
        };

        $scored = $candidates->map(function (PoetryPoem $poem) use ($maxChars, $passedIds, $businessDate, $child) {
            $len = mb_strlen($poem->plainText());
            $score = 100;
            if ($len <= $maxChars) {
                $score += 30;
            } else {
                $score -= min(40, (int) (($len - $maxChars) / 2));
            }
            if (in_array($poem->id, $passedIds, true)) {
                $score -= 50; // prefer not-yet-memorized for weekly learn
            }
            // Stable weekly shuffle
            $seed = crc32($child->id.'|'.$this->weekKey($businessDate).'|'.$poem->id);
            $score += ($seed % 17);

            return ['poem' => $poem, 'score' => $score, 'len' => $len];
        })->sortByDesc('score')->values();

        $best = $scored->first();
        if ($best === null) {
            return null;
        }

        /** @var PoetryPoem $poem */
        $poem = $best['poem'];
        $reason = $age <= 6
            ? "根据你大约 {$age} 岁、一年级水平，本周选了一首短小好记的诗。"
            : "结合年龄（约 {$age} 岁）与年级，本周推荐《{$poem->title}》。";

        return ['poem' => $poem, 'reason' => $reason];
    }

    private function ageYears(ChildProfile $child, CarbonImmutable $businessDate): ?int
    {
        $birth = $child->birth_date;
        if (! is_string($birth) || $birth === '') {
            return null;
        }

        try {
            return $this->clock->ageOn($birth, $businessDate->toDateString());
        } catch (\Throwable) {
            return null;
        }
    }
}
