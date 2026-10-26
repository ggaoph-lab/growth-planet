<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Services\QwenTtsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Prepares today’s fairy tale (and TTS) for the fairy-tale planet. */
class EnglishDailyPreparation
{
    public static function cacheKey(int $childId, string $date, ?string $provider, ?string $model): string
    {
        $engine = hash('sha256', ($provider ?? 'local').'|'.$model);

        return "english:prepare:v3:{$childId}:{$date}:{$engine}";
    }

    public function __construct(
        private Clock $clock,
        private EnglishFairyTaleService $tales,
        private EnglishAiCoach $coach,
        private QwenTtsService $tts,
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
            $state = ['attempts' => ($state['attempts'] ?? 0) + 1, 'ready' => false, 'retry_at' => $local->addMinutes(30)->timestamp];
            Cache::put($key, $state, $local->endOfDay()->addDay());

            $tale = $this->tales->prepareToday($child);
            $texts = ['en-US' => [], 'zh-CN' => []];
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
                ->where('purpose', 'fairy_tale_day')->where('status', 'local')->exists();
            $state['ready'] = ! $localFallback && $audioReady && $tale !== null;
            Cache::put($key, $state, $local->endOfDay()->addDay());

            return ['date' => $date, 'words' => 0, 'ready' => $state['ready'], 'audio_ready' => $audioReady];
        } finally {
            $lock->release();
        }
    }
}
