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
    public const PATH_STANDARD = 'standard';

    public const PATH_RICH = 'rich';

    /** Gentle floor for the high-vocabulary path — still kid-friendly, unlocked further by accuracy. */
    public const RICH_BAND_FLOOR = 2;

    /** Max spelling fill-ins drawn per child per day. */
    public const SPELLING_DAILY_LIMIT = 5;

    public function __construct(
        private readonly Clock $clock,
        private readonly EnglishAiCoach $coach,
        private readonly EnglishContentGate $gate,
    ) {}

    /**
     * @return array{estimated_size: int, band: int, path: string, effective_band: int, learning_words?: list<string>}
     */
    public function summary(ChildProfile $child): array
    {
        $profile = $this->profile($child);

        return [
            'estimated_size' => $profile['estimated_size'],
            'band' => $profile['band'],
            'path' => $profile['path'],
            'effective_band' => $profile['effective_band'],
            'learning_words' => $profile['learning_words'],
        ];
    }

    /**
     * @return array{estimated_size: int, band: int, path: string, effective_band: int, known_words: list<string>, missed_words: list<string>, learning_words: list<string>}
     */
    public function profile(ChildProfile $child): array
    {
        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);
        $learning = $this->decodeList($row->learning_words ?? null);
        $band = (int) ($row->band ?? 1);
        $path = $this->normalizePath((string) ($row->path ?? self::PATH_STANDARD));

        return [
            'estimated_size' => (int) ($row->estimated_size ?? 0),
            'band' => $band,
            'path' => $path,
            'effective_band' => $this->practiceBand($child),
            'known_words' => $known,
            'missed_words' => $missed,
            'learning_words' => $learning,
        ];
    }

    public function normalizePath(string $path): string
    {
        return $path === self::PATH_RICH ? self::PATH_RICH : self::PATH_STANDARD;
    }

    public function effectiveBand(string $path, int $band): int
    {
        $band = max(1, min(6, $band));
        if ($this->normalizePath($path) === self::PATH_RICH) {
            return max(self::RICH_BAND_FLOOR, $band);
        }

        return $band;
    }

    /**
     * Content difficulty: size sets a ceiling, recent accuracy unlocks each step.
     */
    public function practiceBand(ChildProfile $child): int
    {
        $row = $this->profileRow($child->id);
        $path = $this->normalizePath((string) ($row === null ? self::PATH_STANDARD : ($row->path ?? self::PATH_STANDARD)));
        $sizeBand = $this->bandFor((int) ($row === null ? 0 : ($row->estimated_size ?? 0)));
        $stats = $this->recentAccuracyStats($child, 7);
        $cap = $this->accuracyBandCap($stats['count'], $stats['accuracy']);
        $band = min(max(1, $sizeBand), $cap);
        if ($path === self::PATH_RICH) {
            $band = max(self::RICH_BAND_FLOOR, $band);
        }

        return max(1, min(6, $band));
    }

    /**
     * @return array{accuracy: float|null, count: int}
     */
    public function recentAccuracyStats(ChildProfile $child, int $days): array
    {
        $from = $this->clock->today($child->family->timezone)->subDays($days - 1)->toDateString();
        $rows = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', '>=', $from)
            ->get(['correct']);
        if ($rows->isEmpty()) {
            return ['accuracy' => null, 'count' => 0];
        }

        return [
            'accuracy' => round($rows->where('correct', true)->count() / $rows->count(), 3),
            'count' => $rows->count(),
        ];
    }

    private function accuracyBandCap(int $count, ?float $accuracy): int
    {
        // Need a stable sample before climbing; low accuracy stays on easy bands.
        if ($count < 8 || $accuracy === null) {
            return 1;
        }

        return match (true) {
            $accuracy < 0.55 => 1,
            $accuracy < 0.70 => 2,
            $accuracy < 0.80 => 3,
            $accuracy < 0.90 => 4,
            $accuracy < 0.95 => 5,
            default => 6,
        };
    }

    /**
     * Parent sets standard vs high-vocabulary path. Regenerates today's probe/session so content rises immediately.
     *
     * @return array{estimated_size: int, band: int, path: string, effective_band: int, known_words: list<string>, missed_words: list<string>, learning_words: list<string>}
     */
    public function setPath(ChildProfile $child, string $path): array
    {
        $path = $this->normalizePath($path);
        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);
        $learning = $this->decodeList($row->learning_words ?? null);
        $size = (int) ($row->estimated_size ?? count($known));
        $band = $this->bandFor($size);
        if ($path === self::PATH_RICH) {
            $band = max($band, self::RICH_BAND_FLOOR);
            // Seed a modest estimate; accuracy still gates how hard content gets.
            $size = max($size, 41);
        }
        $payload = [
            'estimated_size' => $size,
            'band' => $band,
            'path' => $path,
            'known_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'missed_words' => json_encode($missed, JSON_UNESCAPED_UNICODE),
            'learning_words' => json_encode($learning, JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->clock->now(),
        ];
        if ($row === null) {
            DB::table('english_vocabulary_profiles')->insert($payload + ['child_id' => $child->id]);
        } else {
            DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->update($payload);
        }

        $today = $this->clock->today($child->family->timezone)->toDateString();
        $this->coach->discardDayRecords($child, $today, ['vocabulary_probe', 'session_plan', 'rich_challenges']);

        return $this->profile($child);
    }

    /**
     * Kid taps an unknown word inside a short sentence — save it for spelling practice.
     *
     * @return array{word: string, learning_words: list<string>}
     */
    public function addLearningWord(ChildProfile $child, string $word): array
    {
        $word = $this->gate->expression($word);
        if ($word === '' || str_contains($word, ' ')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'word' => '请点选一个英文单词。',
            ]);
        }
        if (mb_strlen($word) > 24) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'word' => '这个词太长了，换一个短一点的吧。',
            ]);
        }

        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);
        $learning = $this->decodeList($row->learning_words ?? null);
        $learning[] = $word;
        $missed[] = $word;
        $known = array_values(array_filter($known, fn ($item) => $item !== $word));
        $learning = array_slice(array_values(array_unique($learning)), -60);
        $missed = array_slice(array_values(array_unique($missed)), -40);
        $size = count($known);
        $path = $this->normalizePath((string) ($row->path ?? self::PATH_STANDARD));
        $payload = [
            'estimated_size' => $size,
            'band' => $this->bandFor($size),
            'path' => $path,
            'known_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'missed_words' => json_encode($missed, JSON_UNESCAPED_UNICODE),
            'learning_words' => json_encode($learning, JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->clock->now(),
        ];
        if ($row === null) {
            DB::table('english_vocabulary_profiles')->insert($payload + ['child_id' => $child->id]);
        } else {
            DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->update($payload);
        }

        // Redraw today's spelling picks so the new word can appear (still capped at 5).
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $this->coach->discardDayRecords($child, $today, ['spelling_day']);

        return ['word' => $word, 'learning_words' => $learning];
    }

    /**
     * Spelling fill-ins: up to SPELLING_DAILY_LIMIT random learned words per day.
     *
     * @param  list<string>  $answeredKeys  correctly spelled keys for today
     * @return list<array<string, mixed>>
     */
    public function spellingCards(ChildProfile $child, int $slots, array $answeredKeys, string $date): array
    {
        if ($slots < 1) {
            return [];
        }

        $queue = $this->dailySpellingWords($child, $date);
        if ($queue === []) {
            return [];
        }

        $cards = [];
        foreach ($queue as $word) {
            $key = 'spell:'.$this->slug($word);
            if ($key === 'spell:' || in_array($key, $answeredKeys, true)) {
                continue;
            }
            $entry = $this->ladderEntry($word);
            $meaning = (string) ($entry['meaning'] ?? '');
            if ($meaning === '') {
                $meaning = '拼写这个单词';
            }
            $cards[] = [
                'key' => $key,
                'theme' => '拼写填空',
                'kind' => 'spelling',
                'text' => $word,
                'meaning' => $meaning,
                'emoji' => (string) ($entry['emoji'] ?? $this->gate->emojiFor($word, 'word')),
                'ipa' => (string) ($entry['ipa'] ?? ''),
                'pos' => (string) ($entry['pos'] ?? ''),
                'options' => [$word],
                'answer' => $word,
                'blank_hint' => $this->blankHint($word),
                'review' => false,
                'stage' => 0,
                'probe' => true,
                'spelling' => true,
                'challenge' => false,
            ];
            if (count($cards) >= $slots) {
                break;
            }
        }

        return $cards;
    }

    /**
     * Stable daily draw of up to 5 learned words (plus any words the kid saved to study).
     *
     * @return list<string>
     */
    private function dailySpellingWords(ChildProfile $child, string $date): array
    {
        $record = $this->coach->findRecord($child, 'spelling_day', $date);
        if ($record !== null) {
            $items = json_decode((string) $record->items, true);
            if (is_array($items)) {
                $words = [];
                foreach ($items as $item) {
                    if (is_string($item) && $item !== '') {
                        $words[] = $item;
                    } elseif (is_array($item) && isset($item['text'])) {
                        $words[] = (string) $item['text'];
                    }
                }

                return array_values(array_unique($words));
            }
        }

        $profile = $this->profile($child);
        // Prefer words the kid tapped to learn; fill from already-known words.
        $pool = array_values(array_unique([
            ...$profile['learning_words'],
            ...$profile['known_words'],
        ]));
        $pool = array_values(array_filter($pool, fn ($word) => ! str_contains($word, ' ') && mb_strlen($word) >= 2));
        if ($pool === []) {
            return [];
        }

        shuffle($pool);
        $picked = array_slice($pool, 0, self::SPELLING_DAILY_LIMIT);
        $this->coach->storeRecord(
            $child,
            'spelling_day',
            $date,
            ['count' => count($picked), 'limit' => self::SPELLING_DAILY_LIMIT],
            $picked,
            'local',
        );

        return $picked;
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }

    private function blankHint(string $word): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $len = count($chars);
        if ($len <= 2) {
            return implode("\u{00A0}", $chars);
        }
        $keep = [0];
        if ($len >= 4) {
            $keep[] = (int) floor(($len - 1) / 2);
        }
        $keep[] = $len - 1;
        $keep = array_unique($keep);
        $parts = [];
        foreach ($chars as $i => $char) {
            $parts[] = in_array($i, $keep, true) ? $char : '_';
        }

        // Non-breaking spaces keep the whole hint on one line.
        return implode("\u{00A0}", $parts);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ladderEntry(string $word): ?array
    {
        $want = strtolower($word);
        $bands = config('english_vocabulary.bands');
        if (! is_array($bands)) {
            return null;
        }
        foreach ($bands as $levelItems) {
            if (! is_array($levelItems)) {
                continue;
            }
            foreach ($levelItems as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                if (strtolower((string) ($raw['text'] ?? '')) === $want) {
                    return $raw;
                }
            }
        }

        return null;
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

        $practiceBand = $this->practiceBand($child);
        $record = $this->coach->findRecord($child, 'vocabulary_probe', $date);
        if ($record !== null && $this->coach->shouldRetryLocal($child, $record)) {
            $this->coach->discardLocalRecord($child, 'vocabulary_probe', $date);
            $record = null;
        }
        if ($record !== null) {
            $snap = json_decode((string) $record->profile_snapshot, true);
            $recordedBand = is_array($snap) ? (int) ($snap['band'] ?? 0) : 0;
            // Rebuild when accuracy changed the allowed difficulty for today.
            if ($recordedBand !== $practiceBand) {
                $this->coach->discardDayRecords($child, $date, ['vocabulary_probe']);
                $record = null;
            }
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

        // Drop sticky duplicate texts (e.g. garden:r2…r28) left by older refill bugs.
        $record = $this->compactProbeRecord($child, $record, $answered, $date);

        $cards = $this->unansweredProbeCards($record, $answered, $slots);
        // Another round after finishing a batch — grow the same-day pool without a daily stop.
        if (count($cards) < $slots) {
            $record = $this->appendProbeCards($child, $record, $slots - count($cards), $avoidTexts, $answered, $date);
            $cards = $this->unansweredProbeCards($record, $answered, $slots);
        }

        return $this->ensureSentenceMix($cards, $slots, $avoidTexts, $answered, $practiceBand);
    }

    /**
     * @param  list<string>  $answered
     */
    private function compactProbeRecord(ChildProfile $child, object $record, array $answered, string $date): object
    {
        $items = json_decode((string) $record->items, true);
        if (! is_array($items) || $items === []) {
            return $record;
        }

        $answeredLookup = [];
        foreach ($answered as $key) {
            $answeredLookup[(string) $key] = true;
        }

        $answeredTexts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || ! isset($answeredLookup[$key])) {
                continue;
            }
            $expression = $this->gate->expression((string) ($item['text'] ?? ''));
            if ($expression !== '') {
                $answeredTexts[strtolower($expression)] = true;
            }
        }

        $kept = [];
        $queuedTexts = [];
        $keptAnsweredTexts = [];
        $changed = false;
        foreach ($items as $item) {
            if (! is_array($item)) {
                $changed = true;
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            $expression = $this->gate->expression((string) ($item['text'] ?? ''));
            $norm = $expression !== '' ? strtolower($expression) : '';

            // Keep one answered marker per text; drop unanswered clones and extra answered rounds.
            if ($key !== '' && isset($answeredLookup[$key])) {
                if ($norm !== '' && isset($keptAnsweredTexts[$norm])) {
                    $changed = true;
                    continue;
                }
                if ($norm !== '') {
                    $keptAnsweredTexts[$norm] = true;
                }
                $kept[] = $item;
                continue;
            }
            if ($norm !== '' && isset($answeredTexts[$norm])) {
                $changed = true;
                continue;
            }
            if ($norm !== '' && isset($queuedTexts[$norm])) {
                $changed = true;
                continue;
            }
            if ($norm !== '') {
                $queuedTexts[$norm] = true;
            }
            $kept[] = $item;
        }

        if (! $changed) {
            return $record;
        }

        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode(array_values($kept), JSON_UNESCAPED_UNICODE),
        ]);

        return $this->coach->findRecord($child, 'vocabulary_probe', $date) ?? $record;
    }

    /**
     * @param  list<string>  $answered
     * @return list<array<string, mixed>>
     */
    private function unansweredProbeCards(object $record, array $answered, int $slots): array
    {
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return [];
        }

        $answeredLookup = [];
        foreach ($answered as $key) {
            $answeredLookup[(string) $key] = true;
        }

        $answeredTexts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || ! isset($answeredLookup[$key])) {
                continue;
            }
            $expression = $this->gate->expression((string) ($item['text'] ?? ''));
            if ($expression !== '') {
                $answeredTexts[strtolower($expression)] = true;
            }
        }

        $fresh = [];
        $reuse = [];
        $seenFresh = [];
        $seenReuse = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || isset($answeredLookup[$key])) {
                continue;
            }
            $expression = $this->gate->expression((string) ($item['text'] ?? ''));
            $norm = $expression !== '' ? strtolower($expression) : $key;
            if (isset($answeredTexts[$norm])) {
                if (! isset($seenReuse[$norm])) {
                    $reuse[] = $item;
                    $seenReuse[$norm] = true;
                }
                continue;
            }
            if (isset($seenFresh[$norm])) {
                continue;
            }
            $fresh[] = $item;
            $seenFresh[$norm] = true;
        }

        $cards = array_slice($fresh, 0, $slots);
        if (count($cards) < $slots) {
            $cards = [...$cards, ...array_slice($reuse, 0, $slots - count($cards))];
        }

        return $cards;
    }

    /**
     * @param  list<string>  $avoidTexts
     * @param  list<string>  $answered
     */
    private function appendProbeCards(ChildProfile $child, object $record, int $need, array $avoidTexts, array $answered, string $date): object
    {
        if ($need < 1) {
            return $record;
        }

        $existing = json_decode((string) $record->items, true);
        if (! is_array($existing)) {
            $existing = [];
        }

        $profile = $this->profile($child);
        $band = $this->practiceBand($child);
        $answeredLookup = [];
        foreach ($answered as $key) {
            $answeredLookup[(string) $key] = true;
        }

        // Prefer never-seen texts today. Skipping already-answered expressions stops sticky
        // repeats like garden → garden:r2 → garden:r3 from the ladder head.
        $answeredTexts = [];
        $queuedTexts = [];
        foreach ($existing as $item) {
            if (! is_array($item)) {
                continue;
            }
            $expression = $this->gate->expression((string) ($item['text'] ?? ''));
            if ($expression === '') {
                continue;
            }
            $norm = strtolower($expression);
            $key = (string) ($item['key'] ?? '');
            if ($key !== '' && isset($answeredLookup[$key])) {
                $answeredTexts[$norm] = true;
            } elseif ($key !== '') {
                $queuedTexts[$norm] = true;
            }
        }

        $avoid = [];
        foreach ($avoidTexts as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }
        $avoid += $answeredTexts;
        $avoid += $queuedTexts;

        $extra = $this->fillFromLadder([], $need + 8, $avoid, max(1, $band));
        // Full ladder already used today — rotate so reuse does not restart at the same word.
        if (count($extra) < $need) {
            $wrapAvoid = $queuedTexts;
            foreach ($avoidTexts as $text) {
                $expression = $this->gate->expression((string) $text);
                if ($expression !== '') {
                    $wrapAvoid[strtolower($expression)] = true;
                }
            }
            $extra = $this->fillFromLadder($extra, $need + 8, $wrapAvoid, max(1, $band), count($answeredTexts));
        }

        $seenKeys = [];
        foreach ($existing as $item) {
            if (is_array($item) && isset($item['key'])) {
                $seenKeys[(string) $item['key']] = true;
            }
        }
        foreach ($answeredLookup as $key => $_) {
            $seenKeys[$key] = true;
        }

        $added = 0;
        foreach ($extra as $card) {
            $baseKey = (string) ($card['key'] ?? '');
            if ($baseKey === '') {
                continue;
            }
            $key = $this->uniqueProbeKey($baseKey, $seenKeys);
            $card['key'] = $key;
            $existing[] = $card;
            $seenKeys[$key] = true;
            $added++;
            if ($added >= $need) {
                break;
            }
        }

        if ($added < 1) {
            return $record;
        }

        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode(array_values($existing), JSON_UNESCAPED_UNICODE),
            'profile_snapshot' => json_encode([
                'band' => $band,
                'path' => $profile['path'],
                'estimated_size' => $profile['estimated_size'],
                'refilled_at' => $date,
                'count' => count($existing),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $fresh = $this->coach->findRecord($child, 'vocabulary_probe', $date);

        return $fresh ?? $record;
    }

    /**
     * @param  array<string, true>  $seenKeys
     */
    private function uniqueProbeKey(string $baseKey, array $seenKeys): string
    {
        if (! isset($seenKeys[$baseKey])) {
            return $baseKey;
        }

        $round = 2;
        while (isset($seenKeys[$baseKey.':r'.$round])) {
            $round++;
        }

        return $baseKey.':r'.$round;
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
        $learning = $this->decodeList($row->learning_words ?? null);

        if ($correct) {
            $known[] = $expression;
            $missed = array_values(array_filter($missed, fn ($word) => $word !== $expression));
            $learning = array_values(array_filter($learning, fn ($word) => $word !== $expression));
        } else {
            $missed[] = $expression;
            $known = array_values(array_filter($known, fn ($word) => $word !== $expression));
        }

        $known = array_slice(array_values(array_unique($known)), -300);
        $missed = array_slice(array_values(array_unique($missed)), -40);
        $learning = array_slice(array_values(array_unique($learning)), -60);
        $size = count($known);
        $path = $this->normalizePath((string) ($row->path ?? self::PATH_STANDARD));
        $band = $this->bandFor($size);
        if ($path === self::PATH_RICH) {
            $band = max($band, self::RICH_BAND_FLOOR);
        }
        $payload = [
            'estimated_size' => $size,
            'band' => $band,
            'path' => $path,
            'known_words' => json_encode($known, JSON_UNESCAPED_UNICODE),
            'missed_words' => json_encode($missed, JSON_UNESCAPED_UNICODE),
            'learning_words' => json_encode($learning, JSON_UNESCAPED_UNICODE),
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
        $band = $this->practiceBand($child);
        $snapshot = [
            'band' => $band,
            'path' => $profile['path'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($known, -30),
            'missed_words' => array_slice($missed, -15),
            'learning_words' => array_slice($profile['learning_words'], -15),
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
        $system = $band <= 2
            ? 'You write VERY easy English checks for a young child (about ages 5–8, CEFR pre-A1/A1). Use only short everyday words (animal, color, family, food, action) and tiny sentences of at most 5 words. Mix about 2 words : 1 sentence. No hard academic words. Reply JSON only: {"items":[{"kind":"word","text":"bird","meaning":"鸟","emoji":"🐦","ipa":"/bɜːrd/","pos":"n.","options":["鸟","鱼","树"]},{"kind":"sentence","text":"I see a bird.","meaning":"我看见一只鸟。","emoji":"👀","options":["我看见一只鸟。","我想喝水。","这是猫。"]}]}. For words include IPA and pos. Sentences omit ipa/pos. meaning is simplified Chinese. options has exactly 3 different Chinese choices including meaning. Do not repeat known_words. Match band in the user JSON. No personal data.'
            : ($band <= 4
                ? 'You write gentle English checks for a child. Prefer familiar school/home words and short sentences (max 7 words). Avoid obscure academic jargon. Mix about 2 words : 1 sentence. Reply JSON only with the same schema as: {"items":[{"kind":"word","text":"school","meaning":"学校","emoji":"🏫","ipa":"/skuːl/","pos":"n.","options":["学校","公园","商店"]},{"kind":"sentence","text":"I like school.","meaning":"我喜欢学校。","emoji":"🎒","options":["我喜欢学校。","下雨了。","请打开门。"]}]}. meaning is simplified Chinese. options has exactly 3 Chinese choices including meaning. Do not repeat known_words. Match band. No personal data.'
                : 'You write English checks for a child with a growing vocabulary (about CEFR A2). Prefer clear everyday phrases over rare academic words. Mix about 1 word : 1 sentence. Reply JSON only with word/sentence items, IPA/pos for words, simplified Chinese meanings, exactly 3 Chinese options. Do not repeat known_words. Match band. No personal data.');
        $reply = $this->coach->chatJson($child, [
            ['role' => 'system', 'content' => $system],
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
    private function fillFromLadder(array $cards, int $slots, array $avoid, int $band, int $rotate = 0): array
    {
        foreach ($cards as $card) {
            $expression = $this->gate->expression((string) ($card['text'] ?? ''));
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }

        $bands = config('english_vocabulary.bands');
        $pool = [];
        $maxLevel = is_array($bands) ? max(array_map('intval', array_keys($bands))) : 6;
        if (is_array($bands)) {
            // Prefer current band, then easier bands — never reach upward for harder words.
            for ($level = $band; $level >= max(1, $band - 1); $level--) {
                foreach ($bands[$level] ?? [] as $raw) {
                    if (is_array($raw)) {
                        $pool[] = $raw;
                    }
                }
            }
            if (count($pool) < $slots) {
                for ($level = max(1, $band - 2); $level >= 1; $level--) {
                    foreach ($bands[$level] ?? [] as $raw) {
                        if (is_array($raw)) {
                            $pool[] = $raw;
                        }
                    }
                }
            }
        }

        if ($rotate > 0 && $pool !== []) {
            $offset = $rotate % count($pool);
            if ($offset > 0) {
                $pool = array_merge(array_slice($pool, $offset), array_slice($pool, 0, $offset));
            }
        }

        foreach ($pool as $raw) {
            if (count($cards) >= $slots) {
                break;
            }
            $card = $this->gate->probeCard($raw, $avoid, $band);
            if ($card === null) {
                // Ladder items are curated; retry without band filter for fill.
                $card = $this->gate->probeCard($raw, $avoid, $maxLevel);
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
            $size >= 220 => 6,
            $size >= 151 => 5,
            $size >= 81 => 4,
            $size >= 41 => 3,
            $size >= 16 => 2,
            default => 1,
        };
    }
}
