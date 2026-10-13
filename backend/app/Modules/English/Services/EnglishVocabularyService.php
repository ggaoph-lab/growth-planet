<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
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
        private readonly EnglishAiCoach $coach,
        private readonly EnglishContentGate $gate,
    ) {}

    /**
     * @return array{estimated_size: int, band: int}
     */
    public function summary(ChildProfile $child): array
    {
        $profile = $this->profile($child);

        return [
            'estimated_size' => $profile['estimated_size'],
            'band' => $profile['band'],
        ];
    }

    /**
     * @return array{estimated_size: int, band: int, known_words: list<string>, missed_words: list<string>}
     */
    public function profile(ChildProfile $child): array
    {
        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);

        return [
            'estimated_size' => (int) ($row->estimated_size ?? 0),
            'band' => (int) ($row->band ?? 1),
            'known_words' => $known,
            'missed_words' => $missed,
        ];
    }

    public function answeredToday(ChildProfile $child, string $date): int
    {
        return DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where(function ($query) {
                $query->where('item_key', 'like', 'probe:%')
                    ->orWhere('item_key', 'like', 'grammar:%')
                    ->orWhere('item_key', 'like', 'story:%');
            })
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

        $record = $this->coach->findRecord($child, 'vocabulary_probe', $date);
        if ($record !== null && $this->coach->shouldRetryLocal($child, $record)) {
            $this->coach->discardLocalRecord($child, 'vocabulary_probe', $date);
            $record = null;
        }
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

        return $this->ensureSentenceMix($cards, $slots, $avoidTexts, $answered, (int) ($this->profile($child)['band'] ?? 1));
    }

    /**
     * Older word-only probe records still get a short-sentence share.
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  list<string>  $avoidTexts
     * @param  list<string>  $answered
     * @return list<array<string, mixed>>
     */
    private function ensureSentenceMix(array $cards, int $slots, array $avoidTexts, array $answered, int $band): array
    {
        $wantSentences = $slots >= 3 ? max(1, intdiv($slots, 3)) : ($slots >= 2 ? 1 : 0);
        if ($wantSentences < 1) {
            return array_slice($cards, 0, $slots);
        }

        $sentenceCount = count(array_filter($cards, fn ($card) => ($card['kind'] ?? '') === 'sentence'));
        if ($sentenceCount >= $wantSentences) {
            return array_slice($cards, 0, $slots);
        }

        $avoid = [];
        foreach ([...$avoidTexts, ...array_map(fn ($card) => (string) ($card['text'] ?? ''), $cards)] as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }

        $extras = $this->fillFromLadder([], $wantSentences - $sentenceCount + 6, $avoid, max(1, $band));
        foreach ($extras as $extra) {
            if (($extra['kind'] ?? '') !== 'sentence') {
                continue;
            }
            if (in_array($extra['key'] ?? '', $answered, true)) {
                continue;
            }
            // Replace a trailing word card when possible so the list stays within slots.
            $replaced = false;
            for ($i = count($cards) - 1; $i >= 0; $i--) {
                if (($cards[$i]['kind'] ?? 'word') === 'word') {
                    $cards[$i] = $extra;
                    $replaced = true;
                    $sentenceCount++;
                    break;
                }
            }
            if (! $replaced && count($cards) < $slots) {
                $cards[] = $extra;
                $sentenceCount++;
            }
            if ($sentenceCount >= $wantSentences) {
                break;
            }
        }

        return array_slice($cards, 0, $slots);
    }

    public function observe(ChildProfile $child, string $expression, bool $correct): void
    {
        $expression = $this->gate->expression($expression);
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
        $profile = $this->profile($child);
        $known = $profile['known_words'];
        $missed = $profile['missed_words'];
        $band = $profile['band'];
        $snapshot = [
            'band' => $band,
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($known, -30),
            'missed_words' => array_slice($missed, -15),
            'count' => $slots,
        ];
        $avoid = [];
        foreach ([...$avoidTexts, ...$known] as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }

        $provider = null;
        $model = null;
        $fromAi = [];
        $reply = $this->coach->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'You write short English checks for a young child. Mix single words and short everyday sentences (about 2 words per 1 sentence). Reply JSON only: {"items":[{"kind":"word","text":"bird","meaning":"鸟","emoji":"🐦","ipa":"/bɜːrd/","pos":"n.","options":["鸟","鱼","树"]},{"kind":"sentence","text":"I see a bird.","meaning":"我看见一只鸟。","emoji":"👀","options":["我看见一只鸟。","我想喝水。","这是猫。"]}]}. For words include IPA and pos (n./v./adj./adv.). Sentences omit ipa/pos. meaning is simplified Chinese. options has exactly 3 different Chinese choices including meaning. Do not repeat known_words. No personal data.',
            ],
            ['role' => 'user', 'content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)],
        ]);

        if ($reply !== null) {
            $provider = $reply['provider'];
            $model = $reply['model'];
            $fromAi = $this->sanitizeItems($reply['content'], $avoid, $slots, $band);
        }

        $aiCount = count($fromAi);
        $items = $this->fillFromLadder($fromAi, $slots, $avoid, max(1, $band));
        $status = $aiCount === 0 ? 'local' : ($aiCount >= count($items) ? 'ai' : 'mixed');

        return $this->coach->storeRecord(
            $child,
            'vocabulary_probe',
            $date,
            $snapshot,
            $items,
            $status,
            $provider ?? $this->coach->selectedProvider($child),
            $model,
        );
    }

    /**
     * @param  array<string, true>  $avoid
     * @return list<array<string, mixed>>
     */
    private function sanitizeItems(string $content, array $avoid, int $slots, int $band): array
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
            $card = $this->gate->probeCard($raw, $avoid, $band);
            if ($card === null) {
                continue;
            }
            $avoid[strtolower($this->gate->expression($card['text']))] = true;
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
            $expression = $this->gate->expression((string) ($card['text'] ?? ''));
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
            $card = $this->gate->probeCard($raw, $avoid, $band);
            if ($card === null) {
                // Ladder items are curated; retry without band filter for fill.
                $card = $this->gate->probeCard($raw, $avoid, 5);
            }
            if ($card === null) {
                continue;
            }
            $avoid[strtolower($this->gate->expression($card['text']))] = true;
            $cards[] = $card;
        }

        return $cards;
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
            $expression = $this->gate->expression((string) $word);
            if ($expression !== '') {
                $words[] = $expression;
            }
        }

        return array_values(array_unique($words));
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
