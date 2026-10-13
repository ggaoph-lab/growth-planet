<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\RewardPolicy;
use App\Modules\Ledger\Services\RewardGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnglishLearningService
{
    public const DAILY_LIMIT = 10;

    public function __construct(
        private Clock $clock,
        private RewardGrantService $rewards,
        private EnglishVocabularyService $vocabulary,
    ) {}

    public function plan(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $progress = DB::table('english_learning_progress')->where('child_id', $child->id)->get()->keyBy('item_key');
        $completed = $progress->where('completed_date', $today)->count();
        $remaining = max(0, self::DAILY_LIMIT - $completed - $this->vocabulary->answeredToday($child, $today));
        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $due = $progress->filter(fn ($p) => $p->due_date <= $today && $p->completed_date !== $today)
            ->sortBy('due_date');
        $items = [];
        foreach ($due->take($remaining) as $p) {
            if (isset($catalog[$p->item_key])) {
                $card = $catalog[$p->item_key];
                $options = $catalog->filter(fn ($i) => $i['kind'] === $card['kind'] && $i['key'] !== $card['key'])->pluck('text')->take(2)->all();
                $items[] = [...$card, 'options' => [...$options, $card['text']], 'review' => true, 'stage' => $p->stage];
            }
        }
        $newLimit = min(max(0, 3 - $progress->where('introduced_date', $today)->count()), $remaining - count($items));
        foreach ($catalog->reject(fn ($item) => $progress->has($item['key']))->take($newLimit) as $item) {
            $items[] = [...$item, 'review' => false, 'stage' => 0];
        }
        $probeSlots = $remaining - count($items);
        if ($probeSlots > 0) {
            $items = [...$items, ...$this->vocabulary->probes(
                $child,
                $probeSlots,
                array_map(fn ($item) => (string) $item['text'], $items),
                $today,
            )];
        }

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
            $correct = $answer === ($item['review'] ? $item['text'] : $item['meaning']);
            $this->vocabulary->observe($child, (string) $item['text'], $correct);
            if (! empty($item['probe'])) {
                DB::table('english_learning_answers')->insert([
                    'child_id' => $child->id, 'item_key' => $key, 'business_date' => $date,
                    'correct' => $correct, 'created_at' => $this->clock->now(),
                ]);

                return ['correct' => $correct, 'meaning' => $item['meaning'], 'xp' => 0, 'coins' => 0,
                    'next_review' => $date, 'plan' => $this->plan($child), 'probe' => true];
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

            return ['correct' => $correct, 'meaning' => $item['meaning'], 'xp' => $xp, 'coins' => $coins,
                'next_review' => $today->addDays($days)->toDateString(), 'plan' => $this->plan($child)];
        });
    }
}
