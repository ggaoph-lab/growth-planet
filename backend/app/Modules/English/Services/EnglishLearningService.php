<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnglishLearningService
{
    public const DAILY_LIMIT = 10;

    public function __construct(
        private Clock $clock,
        private RewardGrantService $rewards,
        private EnglishVocabularyService $vocabulary,
        private EnglishAiCoach $coach,
        private EnglishDraftService $drafts,
    ) {}

    public function plan(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $progress = DB::table('english_learning_progress')->where('child_id', $child->id)->get()->keyBy('item_key');
        $completed = $progress->where('completed_date', $today)->count();
        $challengeAnswered = $this->vocabulary->answeredToday($child, $today);
        $remaining = max(0, self::DAILY_LIMIT - $completed - $challengeAnswered);
        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $profile = $this->vocabulary->profile($child);
        $accuracy = $this->recentAccuracy($child, 7);
        $newCap = ($accuracy !== null && $accuracy < 0.6) ? 2 : 3;

        $due = $progress->filter(fn ($p) => $p->due_date <= $today && $p->completed_date !== $today)
            ->sortBy('due_date');
        $due = $this->prioritizeMissed($due, $catalog, $profile['missed_words']);

        $items = [];
        foreach ($due->take($remaining) as $p) {
            if (isset($catalog[$p->item_key])) {
                $card = $catalog[$p->item_key];
                $options = $catalog->filter(fn ($i) => $i['kind'] === $card['kind'] && $i['key'] !== $card['key'])->pluck('text')->take(2)->all();
                $items[] = [...$card, 'options' => [...$options, $card['text']], 'review' => true, 'stage' => $p->stage];
            }
        }

        $newLimit = min(max(0, $newCap - $progress->where('introduced_date', $today)->count()), $remaining - count($items));
        foreach ($catalog->reject(fn ($item) => $progress->has($item['key']))->take($newLimit) as $item) {
            $items[] = [...$item, 'review' => false, 'stage' => 0];
        }

        $slotsLeft = $remaining - count($items);
        if ($slotsLeft > 0) {
            $challenges = $this->drafts->publishedCards($child, $slotsLeft, $today);
            $items = [...$items, ...$challenges];
            $slotsLeft = $remaining - count($items);
        }
        if ($slotsLeft > 0) {
            $items = [...$items, ...$this->vocabulary->probes(
                $child,
                $slotsLeft,
                array_map(fn ($item) => (string) ($item['text'] ?? ''), $items),
                $today,
            )];
        }

        $snapshot = [
            'band' => $profile['band'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($profile['known_words'], -30),
            'missed_words' => array_slice($profile['missed_words'], -15),
            'recent_accuracy' => $accuracy,
        ];
        $items = $this->coach->reorderSession($child, $items, $snapshot, $today);
        $this->coach->ensureCoachTips($child, $items, $snapshot, $today);

        return [
            'items' => $items, 'completed' => $completed, 'due' => $due->count(),
            'mastered' => $progress->filter(fn ($p) => $p->stage >= 4)->count(),
            'learned' => $progress->count(), 'total' => $catalog->count(),
            'next_review' => $progress->where('due_date', '>', $today)->min('due_date'),
            'vocabulary' => $this->vocabulary->summary($child),
        ];
    }

    public function answer(ChildProfile $child, string $key, string $answer): array
    {
        return DB::transaction(function () use ($child, $key, $answer) {
            // Serialize answers per child, including new progress rows and reward caps.
            ChildProfile::query()->whereKey($child->id)->lockForUpdate()->firstOrFail();
            $item = collect($this->plan($child)['items'])->firstWhere('key', $key);
            if (! $item || ! in_array($answer, $item['options'], true)) {
                throw ValidationException::withMessages(['answer' => '这道题已完成或不在今天的任务中，请刷新学习任务。']);
            }
            $today = $this->clock->today($child->family->timezone);
            $date = $today->toDateString();
            $isChallenge = ! empty($item['challenge']);
            $isProbe = ! empty($item['probe']);
            if ($isChallenge) {
                $correct = $answer === (string) ($item['correct_answer'] ?? $item['answer'] ?? '');
            } else {
                $correct = $answer === ($item['review'] ? $item['text'] : $item['meaning']);
            }
            $this->vocabulary->observe($child, (string) $item['text'], $correct);

            $coachTip = $correct
                ? $this->coach->encouragement()
                : $this->coach->tipFor(
                    $child,
                    $key,
                    $date,
                    (string) $item['text'],
                    (string) ($item['meaning'] ?? $item['question'] ?? ''),
                );
            if (! $correct && $isChallenge && ! empty($item['coach_tip'])) {
                $coachTip = (string) $item['coach_tip'];
            }

            if ($isProbe || $isChallenge) {
                DB::table('english_learning_answers')->insert([
                    'child_id' => $child->id, 'item_key' => $key, 'business_date' => $date,
                    'correct' => $correct, 'created_at' => $this->clock->now(),
                ]);

                return [
                    'correct' => $correct,
                    'meaning' => $item['meaning'] ?? $item['question'] ?? '',
                    'xp' => 0,
                    'coins' => 0,
                    'next_review' => $date,
                    'plan' => $this->plan($child),
                    'probe' => $isProbe,
                    'challenge' => $isChallenge,
                    'coach_tip' => $coachTip,
                ];
            }

            $query = DB::table('english_learning_progress')->where('child_id', $child->id)->where('item_key', $key);
            $old = $query->first();
            $stage = $correct ? min(4, ($old->stage ?? 0) + 1) : 0;
            $days = $correct ? [1 => 1, 2 => 3, 3 => 7, 4 => 14][$stage] : 0;
            DB::table('english_learning_progress')->updateOrInsert(['child_id' => $child->id, 'item_key' => $key], [
                'stage' => $stage, 'introduced_date' => $old->introduced_date ?? $date,
                'due_date' => $today->addDays($days)->toDateString(),
                'completed_date' => $correct ? $date : null,
            ]);
            $attemptId = DB::table('english_learning_answers')->insertGetId([
                'child_id' => $child->id, 'item_key' => $key, 'business_date' => $date,
                'correct' => $correct, 'created_at' => $this->clock->now(),
            ]);
            $xp = 0;
            $coins = 0;
            if ($correct) {
                $policy = RewardPolicy::query()->firstOrCreate(['family_id' => $child->family_id, 'code' => 'english.practice'], [
                    'xp_amount' => 2, 'coin_amount' => 1, 'requires_approval' => false, 'max_per_day' => self::DAILY_LIMIT, 'is_active' => true,
                ]);
                if ($policy->is_active && ! $policy->requires_approval) {
                    $grant = $this->rewards->grant(new RewardIntent(
                        familyId: $child->family_id, childId: $child->id, policyCode: 'english.practice',
                        grantKey: "english:practice:{$child->id}:{$key}:{$date}", sourceModule: SourceModule::English,
                        sourceType: 'english_learning_answer', sourceId: $attemptId, occurredAt: $this->clock->now(),
                    ));
                    $xp = $grant->xp_amount;
                    $coins = $grant->coin_amount;
                }
            }

            return [
                'correct' => $correct,
                'meaning' => $item['meaning'],
                'xp' => $xp,
                'coins' => $coins,
                'next_review' => $today->addDays($days)->toDateString(),
                'plan' => $this->plan($child),
                'coach_tip' => $coachTip,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function parentProgress(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $plan = $this->plan($child);
        $profile = $this->vocabulary->profile($child);
        $accuracy = $this->recentAccuracy($child, 7);

        return [
            'completed_today' => $plan['completed'],
            'remaining_today' => count($plan['items']),
            'due' => $plan['due'],
            'learned' => $plan['learned'],
            'mastered' => $plan['mastered'],
            'total' => $plan['total'],
            'next_review' => $plan['next_review'],
            'vocabulary' => [
                'estimated_size' => $profile['estimated_size'],
                'band' => $profile['band'],
            ],
            'accuracy_7d' => $accuracy,
            'missed_words' => array_slice($profile['missed_words'], -10),
            'as_of' => $today,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parentSummary(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone);
        $weekStart = $today->startOfWeek()->toDateString();
        $profile = $this->vocabulary->profile($child);
        $accuracy = $this->recentAccuracy($child, 7);
        $themes = $this->recentMissThemes($child);
        $stats = [
            'band' => $profile['band'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($profile['known_words'], -20),
            'missed_words' => array_slice($profile['missed_words'], -10),
            'accuracy_7d' => $accuracy,
            'missed_top' => array_slice($profile['missed_words'], -5),
            'themes' => $themes,
        ];
        $summary = $this->coach->parentWeeklySummary($child, $stats, $weekStart);

        return [
            'week_start' => $weekStart,
            'summary' => $summary['summary'],
            'focuses' => $summary['focuses'],
            'status' => $summary['status'],
            'accuracy_7d' => $accuracy,
            'missed_words' => array_slice($profile['missed_words'], -10),
        ];
    }

    private function recentAccuracy(ChildProfile $child, int $days): ?float
    {
        $from = $this->clock->today($child->family->timezone)->subDays($days - 1)->toDateString();
        $rows = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '>=', $from)
            ->get(['correct']);
        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->where('correct', true)->count() / $rows->count(), 3);
    }

    /**
     * @return list<string>
     */
    private function recentMissThemes(ChildProfile $child): array
    {
        $from = $this->clock->today($child->family->timezone)->subDays(6)->toDateString();
        $keys = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '>=', $from)
            ->where('correct', false)
            ->pluck('item_key')
            ->all();
        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $themes = [];
        foreach ($keys as $key) {
            if (isset($catalog[$key])) {
                $themes[] = (string) $catalog[$key]['theme'];
            }
        }

        return array_values(array_unique($themes));
    }

    /**
     * @param  Collection<string, object>  $due
     * @param  Collection<string, array<string, mixed>>  $catalog
     * @param  list<string>  $missed
     * @return Collection<string, object>
     */
    private function prioritizeMissed($due, $catalog, array $missed)
    {
        if ($missed === []) {
            return $due;
        }
        $missedLookup = [];
        foreach ($missed as $word) {
            $missedLookup[strtolower($word)] = true;
        }

        return $due->sortBy(function ($p) use ($catalog, $missedLookup) {
            $card = $catalog[$p->item_key] ?? null;
            if ($card === null) {
                return '1-'.$p->due_date;
            }
            $text = strtolower((string) $card['text']);
            $hit = isset($missedLookup[$text]);
            foreach (array_keys($missedLookup) as $word) {
                if ($word !== '' && str_contains($text, $word)) {
                    $hit = true;
                    break;
                }
            }

            return ($hit ? '0-' : '1-').$p->due_date;
        })->values();
    }
}
