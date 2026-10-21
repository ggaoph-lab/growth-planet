<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily bilingual fairy-tale reward after 10 new words.
 */
class EnglishFairyTaleService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly EnglishAiCoach $coach,
        private readonly EnglishVocabularyService $vocabulary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forChild(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $completed = $this->vocabulary->newWordsCompletedToday($child, $today);
        $goal = $this->vocabulary->dailyWordGoal($child, $today);
        $unlocked = $completed >= $goal;

        if (! $unlocked) {
            return [
                'available' => false,
                'reason' => 'need_new_words',
                'new_words_completed' => $completed,
                'new_words_goal' => $goal,
                'heard' => false,
                'tale' => null,
                'pack_words' => [],
            ];
        }

        $tale = $this->ensureTale($child, $today);
        $heard = $this->heardToday($child, $today);

        return [
            'available' => $tale !== null,
            'reason' => $tale === null ? 'unavailable' : null,
            'new_words_completed' => $completed,
            'new_words_goal' => $goal,
            'heard' => $heard,
            'tale' => $tale,
            'pack_words' => $tale !== null ? $this->vocabulary->packWordTexts($child, $today) : [],
        ];
    }

    /** Prepare content only; this never unlocks the tale or records listening. */
    public function prepareToday(ChildProfile $child): ?array
    {
        return $this->ensureTale($child, $this->clock->today($child->family->timezone)->toDateString());
    }

    /**
     * Save an English word from a tale into today's new-word pack.
     *
     * @return array<string, mixed>
     */
    public function collectWord(ChildProfile $child, string $word, ?string $date): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $sourceDate = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $today;
        $payload = $sourceDate === $today ? $this->forChild($child) : $this->forDate($child, $sourceDate);
        $tale = $payload['tale'] ?? null;
        if (! is_array($tale)) {
            throw ValidationException::withMessages([
                'word' => '先打开一篇童话，再点里面的英文单词。',
            ]);
        }

        return $this->vocabulary->appendCollectedDailyWord($child, $today, $word, $tale);
    }

    /**
     * @return array<string, mixed>
     */
    public function markHeard(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $completed = $this->vocabulary->newWordsCompletedToday($child, $today);
        if ($completed < $this->vocabulary->dailyWordGoal($child, $today)) {
            return $this->forChild($child);
        }

        $exists = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $today)
            ->where('item_key', 'fairy-tale:heard')
            ->exists();
        if (! $exists) {
            DB::table('english_learning_answers')->insert([
                'child_id' => $child->id,
                'item_key' => 'fairy-tale:heard',
                'business_date' => $today,
                'correct' => true,
                'created_at' => $this->clock->now(),
            ]);
        }

        return $this->forChild($child);
    }

    /**
     * Past daily tales the child can open again (title list).
     *
     * @return list<array<string, mixed>>
     */
    public function history(ChildProfile $child): array
    {
        $rows = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'fairy_tale_day')
            ->orderByDesc('business_date')
            ->limit(40)
            ->get(['business_date', 'items']);

        $heardDates = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('item_key', 'fairy-tale:heard')
            ->pluck('business_date')
            ->map(fn ($date) => (string) $date)
            ->all();
        $heardLookup = array_fill_keys($heardDates, true);

        $out = [];
        foreach ($rows as $row) {
            $items = json_decode((string) $row->items, true);
            $tale = is_array($items) && is_array($items[0] ?? null)
                ? $this->normalizeTale($items[0])
                : null;
            if ($tale === null) {
                continue;
            }
            $date = (string) $row->business_date;
            $out[] = [
                'date' => $date,
                'id' => $tale['id'],
                'title_zh' => $tale['title_zh'],
                'title_en' => $tale['title_en'],
                'source' => $tale['source'],
                'source_en' => $tale['source_en'],
                'heard' => isset($heardLookup[$date]),
            ];
        }

        return $out;
    }

    /**
     * Re-read a cached tale by business date (no unlock check for past days).
     *
     * @return array<string, mixed>
     */
    public function forDate(ChildProfile $child, string $date): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        if ($date === $today) {
            return $this->forChild($child);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [
                'available' => false,
                'reason' => 'invalid_date',
                'new_words_completed' => 0,
                'new_words_goal' => EnglishVocabularyService::NEW_WORDS_DAILY_GOAL,
                'heard' => false,
                'tale' => null,
                'date' => $date,
                'pack_words' => [],
            ];
        }

        $record = $this->coach->findRecord($child, 'fairy_tale_day', $date);
        $tale = null;
        if ($record !== null) {
            $items = json_decode((string) $record->items, true);
            if (is_array($items) && is_array($items[0] ?? null)) {
                $tale = $this->normalizeTale($items[0]);
            }
        }

        return [
            'available' => $tale !== null,
            'reason' => $tale === null ? 'unavailable' : null,
            'new_words_completed' => $this->vocabulary->newWordsCompletedToday($child, $date),
            'new_words_goal' => EnglishVocabularyService::NEW_WORDS_DAILY_GOAL,
            'heard' => $this->heardOnDate($child, $date),
            'tale' => $tale,
            'date' => $date,
            'pack_words' => $tale !== null ? $this->vocabulary->packWordTexts($child, $today) : [],
        ];
    }

    public function heardCount(ChildProfile $child): int
    {
        return (int) DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('item_key', 'fairy-tale:heard')
            ->count();
    }

    public function heardOnDate(ChildProfile $child, string $date): bool
    {
        return DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('item_key', 'fairy-tale:heard')
            ->exists();
    }

    private function heardToday(ChildProfile $child, string $date): bool
    {
        return $this->heardOnDate($child, $date);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ensureTale(ChildProfile $child, string $date): ?array
    {
        $record = $this->coach->findRecord($child, 'fairy_tale_day', $date);
        if ($record !== null && $this->coach->shouldRetryLocal($child, $record)) {
            $this->coach->discardLocalRecord($child, 'fairy_tale_day', $date);
            $record = null;
        }
        if ($record === null) {
            $record = $this->createTale($child, $date);
        }

        $items = json_decode((string) $record->items, true);
        if (! is_array($items) || $items === []) {
            return null;
        }
        $tale = $items[0] ?? null;

        return is_array($tale) ? $this->normalizeTale($tale) : null;
    }

    private function createTale(ChildProfile $child, string $date): object
    {
        $seeds = config('english_fairy_tales.seeds', []);
        $seed = is_array($seeds) && $seeds !== []
            ? $seeds[((crc32($child->id.'|'.$date) % count($seeds)) + count($seeds)) % count($seeds)]
            : [
                'id' => 'red-riding-hood',
                'title_zh' => '小红帽',
                'title_en' => 'Little Red Riding Hood',
                'source' => '格林童话',
                'source_en' => 'Grimm',
            ];

        $snapshot = [
            'seed_id' => $seed['id'] ?? 'red-riding-hood',
            'title_zh' => $seed['title_zh'] ?? '',
            'title_en' => $seed['title_en'] ?? '',
        ];

        $provider = null;
        $model = null;
        $reply = $this->coach->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'You adapt a classic children’s fairy tale into a very short bilingual bedtime reading for a young child (ages 5–8). Reply JSON only: {"title_zh":"...","title_en":"...","source":"...","zh_lines":["中文短句1","中文短句2"],"en_lines":["English sentence 1","English sentence 2"]}. Use 8–12 short Chinese lines and the same number of matching English lines. Keep the plot gentle, no gore or fear. Simplified Chinese. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'seed' => $seed,
                    'style' => 'warm, clear, age-appropriate',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $parsed = null;
        if ($reply !== null) {
            $provider = $reply['provider'];
            $model = $reply['model'];
            $parsed = $this->parseTale($reply['content'], $seed);
        }

        $tale = $parsed ?? $this->fallbackTale($seed);
        $status = $parsed !== null ? 'ai' : 'local';

        return $this->coach->storeRecord(
            $child,
            'fairy_tale_day',
            $date,
            $snapshot,
            [$tale],
            $status,
            $provider ?? $this->coach->selectedProvider($child),
            $model,
        );
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>|null
     */
    private function parseTale(string $content, array $seed): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->normalizeTale([
            'id' => (string) ($seed['id'] ?? 'tale'),
            'title_zh' => (string) ($decoded['title_zh'] ?? $seed['title_zh'] ?? ''),
            'title_en' => (string) ($decoded['title_en'] ?? $seed['title_en'] ?? ''),
            'source' => (string) ($decoded['source'] ?? $seed['source'] ?? ''),
            'source_en' => (string) ($seed['source_en'] ?? ''),
            'zh_lines' => $decoded['zh_lines'] ?? [],
            'en_lines' => $decoded['en_lines'] ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function fallbackTale(array $seed): array
    {
        $titleZh = (string) ($seed['title_zh'] ?? '小红帽');
        $titleEn = (string) ($seed['title_en'] ?? 'Little Red Riding Hood');

        return $this->normalizeTale([
            'id' => (string) ($seed['id'] ?? 'red-riding-hood'),
            'title_zh' => $titleZh,
            'title_en' => $titleEn,
            'source' => (string) ($seed['source'] ?? '格林童话'),
            'source_en' => (string) ($seed['source_en'] ?? 'Grimm'),
            'zh_lines' => [
                '从前，有一个可爱的小女孩，大家叫她小红帽。',
                '有一天，妈妈请她给奶奶送点心。',
                '小红帽走过森林，看见美丽的花。',
                '她采了一些花，想让奶奶开心。',
                '后来她安全地到了奶奶家。',
                '奶奶笑着说：谢谢你，小红帽。',
                '她们一起吃点心，过得很快乐。',
                '小红帽答应妈妈：下次会小心走大路。',
            ],
            'en_lines' => [
                'Once there was a sweet girl called Little Red Riding Hood.',
                'One day, her mother asked her to take treats to Grandma.',
                'She walked through the forest and saw pretty flowers.',
                'She picked some flowers to make Grandma happy.',
                'Then she arrived safely at Grandma’s house.',
                'Grandma smiled and said, “Thank you, Little Red Riding Hood.”',
                'They ate the treats together and felt happy.',
                'Little Red Riding Hood promised to stay on the safe path next time.',
            ],
        ]) ?? [
            'id' => 'red-riding-hood',
            'title_zh' => $titleZh,
            'title_en' => $titleEn,
            'source' => '格林童话',
            'source_en' => 'Grimm',
            'zh_lines' => ['从前有一个小女孩。'],
            'en_lines' => ['Once there was a little girl.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $tale
     * @return array<string, mixed>|null
     */
    private function normalizeTale(array $tale): ?array
    {
        $zh = [];
        foreach ($tale['zh_lines'] ?? [] as $line) {
            $line = trim((string) $line);
            if ($line !== '' && mb_strlen($line) <= 80) {
                $zh[] = $line;
            }
        }
        $en = [];
        foreach ($tale['en_lines'] ?? [] as $line) {
            $line = trim((string) $line);
            if ($line !== '' && mb_strlen($line) <= 160) {
                $en[] = $line;
            }
        }
        if ($zh === [] || $en === []) {
            return null;
        }
        $n = min(count($zh), count($en), 15);
        $zh = array_slice($zh, 0, $n);
        $en = array_slice($en, 0, $n);

        return [
            'id' => (string) ($tale['id'] ?? 'tale'),
            'title_zh' => mb_substr(trim((string) ($tale['title_zh'] ?? '')), 0, 40),
            'title_en' => mb_substr(trim((string) ($tale['title_en'] ?? '')), 0, 80),
            'source' => mb_substr(trim((string) ($tale['source'] ?? '')), 0, 40),
            'source_en' => mb_substr(trim((string) ($tale['source_en'] ?? '')), 0, 40),
            'zh_lines' => $zh,
            'en_lines' => $en,
        ];
    }
}
