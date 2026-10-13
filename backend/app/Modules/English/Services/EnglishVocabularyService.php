<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\HomeworkLlmRouter;
use App\Modules\Task\Services\HomeworkLlmTimeoutException;
use Illuminate\Support\Facades\DB;

/**
 * Vocabulary estimate belongs to the child, not to an AI vendor.
 * A selected engine may propose probe cards; the known/missed list is what
 * the next vendor receives after a switch.
 */
class EnglishVocabularyService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly HomeworkLlmRouter $llm,
        private readonly FamilyAiConfig $ai,
    ) {}

    /**
     * @return array{estimated_size: int, band: int}
     */
    public function summary(ChildProfile $child): array
    {
        $row = $this->profileRow($child->id);

        return [
            'estimated_size' => (int) ($row->estimated_size ?? 0),
            'band' => (int) ($row->band ?? 1),
        ];
    }

    public function answeredToday(ChildProfile $child, string $date): int
    {
        return DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('item_key', 'like', 'probe:%')
            ->pluck('item_key')
            ->unique()
            ->count();
    }

    /**
     * @param  list<string>  $avoidTexts
     * @return list<array<string, mixed>>
     */
    public function probes(ChildProfile $child, int $slots, array $avoidTexts, string $date): array
    {
        if ($slots < 1) {
            return [];
        }

        $record = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('purpose', 'vocabulary_probe')
            ->first();

        if ($record === null) {
            $record = $this->createRecord($child, $slots, $avoidTexts, $date);
        }

        $answered = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('item_key', 'like', 'probe:%')
            ->pluck('item_key')
            ->all();

        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return [];
        }

        $cards = [];
        foreach ($items as $item) {
            if (! is_array($item) || in_array($item['key'] ?? '', $answered, true)) {
                continue;
            }
            $cards[] = $item;
            if (count($cards) >= $slots) {
                break;
            }
        }

        return $cards;
    }

    public function observe(ChildProfile $child, string $expression, bool $correct): void
    {
        $expression = $this->expression($expression);
        if ($expression === '') {
            return;
        }

        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);

        if ($correct) {
            $known[] = $expression;
            $missed = array_values(array_filter($missed, fn ($word) => $word !== $expression));
        } else {
            $missed[] = $expression;
            $known = array_values(array_filter($known, fn ($word) => $word !== $expression));
        }

        $known = array_slice(array_values(array_unique($known)), -300);
        $missed = array_slice(array_values(array_unique($missed)), -40);
        $size = count($known);
        $payload = [
            'estimated_size' => $size,
            'band' => $this->bandFor($size),
            'known_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'missed_words' => json_encode($missed, JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->clock->now(),
        ];

        if ($row === null) {
            DB::table('english_vocabulary_profiles')->insert($payload + ['child_id' => $child->id]);

            return;
        }

        DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->update($payload);
    }

    private function createRecord(ChildProfile $child, int $slots, array $avoidTexts, string $date): object
    {
        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);
        $size = (int) ($row->estimated_size ?? 0);
        $band = (int) ($row->band ?? 1);
        $snapshot = [
            'band' => $band,
            'estimated_size' => $size,
            'known_words' => array_slice($known, -30),
            'missed_words' => array_slice($missed, -15),
            'count' => $slots,
        ];
        $avoid = [];
        foreach ([...$avoidTexts, ...$known] as $text) {
            $expression = $this->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }

        $cfg = $this->ai->resolve((int) $child->family_id);
        $provider = null;
        $model = null;
        $fromAi = [];
        $choice = strtolower(trim((string) ($cfg['provider'] ?? 'auto')));
        if (in_array($choice, ['gemini', 'qwen', 'openai'], true)) {
            [$primary] = $this->llm->attemptsFor($choice, (int) $child->family_id);
            if ($primary !== null) {
                $provider = $primary['client']->name();
                $model = (string) ($primary['config']['model'] ?? '');
                $fromAi = $this->askModel($primary['client'], $primary['config'], $snapshot, $avoid, $slots);
                $model = $fromAi['model'] ?? $model;
                $fromAi = $fromAi['items'];
            }
        }

        $items = $this->fillFromLadder($fromAi, $slots, $avoid, max(1, $band));
        $status = $fromAi === [] ? 'local' : 'ai';

        $id = DB::table('english_ai_records')->insertGetId([
            'child_id' => $child->id,
            'provider' => $provider,
            'model' => $model,
            'purpose' => 'vocabulary_probe',
            'business_date' => $date,
            'profile_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'created_at' => $this->clock->now(),
        ]);

        $created = DB::table('english_ai_records')->where('id', $id)->first();
        if ($created === null) {
            throw new \RuntimeException('english_ai_record_missing');
        }

        return $created;
    }

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, count: int}  $snapshot
     * @param  array<string, true>  $avoid
     * @param  array{api_key: string, model: string}  $config
     * @return array{items: list<array<string, mixed>>, model?: string}
     */
    private function askModel(object $client, array $config, array $snapshot, array $avoid, int $slots): array
    {
        try {
            $result = $client->chatJson([
                [
                    'role' => 'system',
                    'content' => 'You write short English vocabulary checks for a young child. Reply with JSON only: {"items":[{"text":"bird","meaning":"鸟","emoji":"🐦","options":["鸟","鱼","树"]}]}. text is simple English the child might or might not know. meaning is simplified Chinese. options has exactly 3 different Chinese choices and includes meaning. Do not repeat known_words. No personal data.',
                ],
                ['role' => 'user', 'content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)],
            ], $config);
        } catch (HomeworkLlmTimeoutException) {
            return ['items' => []];
        }

        if ($result === null) {
            return ['items' => []];
        }

        return [
            'items' => $this->sanitizeItems((string) $result['content'], $avoid, $slots),
            'model' => (string) ($result['model'] ?? ''),
        ];
    }

    /**
     * @param  array<string, true>  $avoid
     * @return list<array<string, mixed>>
     */
    private function sanitizeItems(string $content, array $avoid, int $slots): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        $rawItems = is_array($decoded) ? ($decoded['items'] ?? []) : [];
        if (! is_array($rawItems)) {
            return [];
        }

        $cards = [];
        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $card = $this->cardFromRaw($raw, $avoid);
            if ($card === null) {
                continue;
            }
            $avoid[strtolower($this->expression($card['text']))] = true;
            $cards[] = $card;
            if (count($cards) >= $slots) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, true>  $avoid
     * @return list<array<string, mixed>>
     */
    private function fillFromLadder(array $cards, int $slots, array $avoid, int $band): array
    {
        foreach ($cards as $card) {
            $expression = $this->expression((string) ($card['text'] ?? ''));
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }

        $bands = config('english_vocabulary.bands');
        $pool = [];
        if (is_array($bands)) {
            for ($level = $band; $level <= min(5, $band + 1); $level++) {
                foreach ($bands[$level] ?? [] as $raw) {
                    if (is_array($raw)) {
                        $pool[] = $raw;
                    }
                }
            }
            if (count($pool) < $slots) {
                foreach ($bands as $levelItems) {
                    foreach ($levelItems as $raw) {
                        if (is_array($raw)) {
                            $pool[] = $raw;
                        }
                    }
                }
            }
        }

        foreach ($pool as $raw) {
            if (count($cards) >= $slots) {
                break;
            }
            $card = $this->cardFromRaw($raw, $avoid);
            if ($card === null) {
                continue;
            }
            $avoid[strtolower($this->expression($card['text']))] = true;
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, true>  $avoid
     * @return array<string, mixed>|null
     */
    private function cardFromRaw(array $raw, array $avoid): ?array
    {
        $text = $this->expression((string) ($raw['text'] ?? ''));
        $meaning = trim((string) ($raw['meaning'] ?? ''));
        if ($text === '' || $meaning === '' || mb_strlen($meaning) > 20) {
            return null;
        }
        if (preg_match('/^[A-Za-z][A-Za-z \'.?!,-]{0,39}$/', $text) !== 1) {
            return null;
        }
        if (isset($avoid[strtolower($text)])) {
            return null;
        }

        $options = [];
        foreach ($raw['options'] ?? [] as $option) {
            $option = trim((string) $option);
            if ($option !== '' && mb_strlen($option) <= 20 && ! in_array($option, $options, true)) {
                $options[] = $option;
            }
        }
        if (! in_array($meaning, $options, true)) {
            array_unshift($options, $meaning);
        }
        $options = array_slice(array_values(array_unique($options)), 0, 3);
        if (count($options) < 3 || ! in_array($meaning, $options, true)) {
            return null;
        }

        $emoji = trim((string) ($raw['emoji'] ?? '✨'));
        if ($emoji === '' || mb_strlen($emoji) > 8) {
            $emoji = '✨';
        }

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        if ($slug === '') {
            return null;
        }

        return [
            'key' => 'probe:'.mb_substr($slug, 0, 50),
            'theme' => '词汇小测试',
            'kind' => 'word',
            'text' => $text,
            'meaning' => $meaning,
            'emoji' => $emoji,
            'options' => $options,
            'review' => false,
            'stage' => 0,
            'probe' => true,
        ];
    }

    private function profileRow(int $childId): ?object
    {
        return DB::table('english_vocabulary_profiles')->where('child_id', $childId)->first();
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            $decoded = $json;
        } else {
            $decoded = json_decode((string) $json, true);
        }
        if (! is_array($decoded)) {
            return [];
        }

        $words = [];
        foreach ($decoded as $word) {
            $expression = $this->expression((string) $word);
            if ($expression !== '') {
                $words[] = $expression;
            }
        }

        return array_values(array_unique($words));
    }

    private function expression(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || mb_strlen($text) > 40) {
            return '';
        }
        if (! str_contains($text, ' ')) {
            return strtolower($text);
        }

        return $text;
    }

    private function bandFor(int $size): int
    {
        return match (true) {
            $size >= 151 => 5,
            $size >= 81 => 4,
            $size >= 41 => 3,
            $size >= 16 => 2,
            default => 1,
        };
    }
}
