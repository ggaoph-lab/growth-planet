<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Services\QwenTtsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Stores reusable content, never a snapshot of answers, rewards or the live queue. */
class EnglishDailyPreparation
{
    public static function cacheKey(int $childId, string $date, ?string $provider, ?string $model): string
    {
        $engine = hash('sha256', ($provider ?? 'local').'|'.$model);

        return "english:prepare:v2:{$childId}:{$date}:{$engine}";
    }

    public function __construct(
        private Clock $clock,
        private EnglishLearningService $learning,
        private EnglishVocabularyService $vocabulary,
        private EnglishFairyTaleService $tales,
        private EnglishAiCoach $coach,
        private QwenTtsService $tts,
        private EnglishWordUsage $wordUsage,
    ) {}

    /** Returns null when not due, already prepared, or another process holds the lock. */
    public function prepare(ChildProfile $child, bool $now = false): ?array
    {
        if (! \App\Modules\Identity\Support\KidPlanets::enabled('english_planet', (int) $child->family_id)) {
            return null;
        }

        return $this->coach->preparingDay(fn () => $this->prepareDue($child, $now));
    }

    private function prepareDue(ChildProfile $child, bool $now): ?array
    {
        $local = $this->clock->now()->setTimezone($child->family->timezone);
        if (! $now && $local->hour < 5) {
            return null;
        }

        $date = $local->toDateString();
        $key = self::cacheKey($child->id, $date, $this->coach->selectedProvider($child), $this->coach->selectedModel($child));
        $lock = Cache::lock($key.':lock', 1800);
        if (! $lock->get()) {
            return null;
        }

        try {
            $state = Cache::get($key, []);
            if (($state['ready'] ?? false) || ($state['attempts'] ?? 0) >= 3 || ($state['retry_at'] ?? 0) > $local->timestamp) {
                return null;
            }
            // Persist the attempt before upstream calls, so failures cannot create a retry storm.
            $state = ['attempts' => ($state['attempts'] ?? 0) + 1, 'ready' => false, 'retry_at' => $local->addMinutes(30)->timestamp];
            Cache::put($key, $state, $local->endOfDay()->addDay());

            $plan = $this->learning->plan($child);
            $cards = $this->vocabulary->dailyWordPack($child, $date);
            $cards = $this->wordUsage->prepare($child, $date, $cards);
            $tale = $this->tales->prepareToday($child);
            $profile = $this->vocabulary->profile($child);
            $snapshot = [
                'band' => $this->vocabulary->practiceBand($child),
                'path' => $profile['path'],
                'estimated_size' => $profile['estimated_size'],
                'known_words' => array_slice($profile['known_words'], -30),
                'missed_words' => array_slice($profile['missed_words'], -15),
                'learning_words' => array_slice($profile['learning_words'], -15),
                'recent_accuracy' => $this->vocabulary->recentAccuracyStats($child, 7)['accuracy'],
            ];
            // Prepare the first later round without bypassing any child-facing unlock checks.
            $later = $this->vocabulary->spellingCards($child, EnglishVocabularyService::SPELLING_DAILY_LIMIT, [], $date);
            $later = [...$later, ...$this->vocabulary->probes($child, EnglishLearningService::SESSION_SIZE, [], $date)];
            if ($profile['path'] === EnglishVocabularyService::PATH_RICH && $snapshot['band'] >= 3) {
                $later = [...$later, ...$this->coach->richDailyChallenges($child, 2, $snapshot, $date)];
            }
            $preview = collect([...$cards, ...$plan['items'], ...$later])->unique('key')->values()->all();
            $this->coach->reorderSession($child, $preview, $snapshot, $date);
            $this->coach->ensureCoachTips($child, $preview, $snapshot, $date);
            $texts = ['en-US' => [], 'zh-CN' => []];
            foreach ($preview as $card) {
                $texts['en-US'][] = (string) ($card['text'] ?? '');
                $texts['zh-CN'][] = (string) ($card['meaning'] ?? '');
                foreach (['phrase', 'sentence'] as $kind) {
                    $texts['en-US'][] = (string) ($card['usage'][$kind]['text'] ?? '');
                    $texts['zh-CN'][] = (string) ($card['usage'][$kind]['meaning'] ?? '');
                }
                foreach ($card['options'] ?? [] as $option) {
                    $texts[preg_match('/\p{Han}/u', $option) ? 'zh-CN' : 'en-US'][] = $option;
                }
            }
            foreach (['en-US' => 'en_lines', 'zh-CN' => 'zh_lines'] as $lang => $field) {
                foreach ($tale[$field] ?? [] as $line) {
                    $texts[$lang][] = $line;
                }
            }
            $audioReady = true;
            foreach ($texts as $lang => $phrases) {
                foreach (array_chunk(array_values(array_unique(array_filter($phrases))), 8) as $batch) {
                    foreach ($this->tts->warm($batch, $lang, (int) $child->family_id) as $audio) {
                        $audioReady = $audioReady && ! empty($audio['audio_base64']);
                    }
                }
            }

            $localFallback = $this->coach->selectedProvider($child) !== null && DB::table('english_ai_records')
                ->where('child_id', $child->id)->where('business_date', $date)
                ->whereIn('purpose', ['daily_word_pack', 'word_usage_day', 'fairy_tale_day', 'vocabulary_probe', 'session_plan', 'coach_tips'])->where('status', 'local')->exists();
            $usageReady = collect($cards)->every(fn ($card) => $card['usage'] !== null);
            $state['ready'] = ! $localFallback && $audioReady && $usageReady && $tale !== null;
            Cache::put($key, $state, $local->endOfDay()->addDay());

            return ['date' => $date, 'words' => count($cards), 'ready' => $state['ready'], 'audio_ready' => $audioReady];
        } finally {
            $lock->release();
        }
    }
}
