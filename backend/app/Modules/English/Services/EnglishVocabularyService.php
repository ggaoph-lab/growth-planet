<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Target new AI words introduced and completed per child per day. */
    public const NEW_WORDS_DAILY_GOAL = 10;

    /** Max brand-new word cards offered in one queue refill (keeps each round short). */
    public const NEW_WORDS_SESSION_CAP = 5;

    /** English→Chinese probe corrects required before a word becomes a spelling card. */
    public const PROBE_MC_GOAL = 2;

    /** Singleton business_date for durable per-child word mastery ledger. */
    private const MASTERY_DATE = '2000-01-01';

    private const MASTERY_PURPOSE = 'probe_mastery';

    public function __construct(
        private readonly Clock $clock,
        private readonly EnglishAiCoach $coach,
        private readonly EnglishContentGate $gate,
        private readonly EnglishWordUsage $wordUsage,
        private readonly EnglishSkillPilot $skillPilot,
    ) {}

    /**
     * Today's AI (or ladder) word pack as course cards with keys word:{slug}.
     *
     * @return list<array<string, mixed>>
     */
    public function dailyWordPack(ChildProfile $child, string $date): array
    {
        $record = $this->coach->findRecord($child, 'daily_word_pack', $date);
        if ($record !== null && $this->coach->shouldRetryLocal($child, $record)) {
            $this->coach->discardLocalRecord($child, 'daily_word_pack', $date);
            $record = null;
        }
        if ($record === null) {
            $record = $this->createDailyWordPack($child, $date);
        }

        $cards = $this->cardsFromDailyWordRecord($record);
        $cards = $this->removePreviouslyLearnedPackWords($child, $date, $record, $cards);
        // Pack can land short (AI/ladder miss) or get wiped by a mid-day local retry.
        // Top up so kids are never stuck below the daily new-word goal with an empty queue.
        $completed = $this->newWordsCompletedToday($child, $date);
        $needPending = max(0, self::NEW_WORDS_DAILY_GOAL - $completed);
        $packKeys = [];
        foreach ($cards as $card) {
            $key = (string) ($card['key'] ?? '');
            if ($key !== '') {
                $packKeys[] = $key;
            }
        }
        $doneLookup = [];
        if ($packKeys !== []) {
            $doneLookup = DB::table('english_learning_progress')
                ->where('child_id', $child->id)
                ->where('completed_date', $date)
                ->whereIn('item_key', $packKeys)
                ->pluck('item_key')
                ->mapWithKeys(fn ($key) => [(string) $key => true])
                ->all();
        }
        $pending = 0;
        foreach ($packKeys as $key) {
            if (! isset($doneLookup[$key])) {
                $pending++;
            }
        }
        if ($needPending > 0 && $pending < $needPending) {
            $record = $this->topUpDailyWordPack($child, $date, $record, $cards, $needPending - $pending);
            $cards = $this->cardsFromDailyWordRecord($record);
        }

        // May exceed the daily goal when earlier short packs were topped up after some words were done.
        return $this->wordUsage->attach($child, $date, $cards);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardsFromDailyWordRecord(object $record): array
    {
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return [];
        }

        $cards = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $card = $this->asDailyWordCard($item);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /** Keep today's completed cards for history, but replace stale unstarted words. */
    private function removePreviouslyLearnedPackWords(ChildProfile $child, string $date, object $record, array $cards): array
    {
        $known = array_fill_keys(array_map('strtolower', $this->profile($child)['known_words']), true);
        $keys = array_column($cards, 'key');
        $progress = DB::table('english_learning_progress')
            ->where('child_id', $child->id)->whereIn('item_key', $keys)->get()->keyBy('item_key');
        $kept = [];
        foreach ($cards as $card) {
            $row = $progress->get((string) ($card['key'] ?? ''));
            $word = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
            $alreadySeen = $row !== null || isset($known[$word]);
            if ($alreadySeen && ($row?->completed_date ?? null) !== $date && empty($card['collected'])) {
                continue;
            }
            $kept[] = $card;
        }
        if (count($kept) !== count($cards)) {
            DB::table('english_ai_records')->where('id', $record->id)->update([
                'items' => json_encode($kept, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $kept;
    }

    /**
     * Append more daily-word cards onto today's pack (update in place — do not insert a second row).
     *
     * @param  list<array<string, mixed>>  $existingCards
     */
    private function topUpDailyWordPack(
        ChildProfile $child,
        string $date,
        object $record,
        array $existingCards,
        int $need,
    ): object {
        if ($need < 1) {
            return $record;
        }

        $band = $this->practiceBand($child);
        $avoid = $this->dailyWordAvoidList($child, $date, $existingCards);
        $extra = $this->fillDailyWordPackToGoal([], $need, $avoid, max(1, $band));
        if ($extra === []) {
            return $record;
        }

        $merged = array_values($existingCards);
        foreach ($extra as $card) {
            $merged[] = $card;
        }

        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'status' => ((string) ($record->status ?? '')) === 'ai' ? 'mixed' : (string) ($record->status ?? 'local'),
            'profile_snapshot' => json_encode(array_merge(
                json_decode((string) ($record->profile_snapshot ?? '{}'), true) ?: [],
                ['pack_size' => count($merged), 'topped_up' => true],
            ), JSON_UNESCAPED_UNICODE),
        ]);

        $fresh = DB::table('english_ai_records')->where('id', $record->id)->first();

        return $fresh ?? $record;
    }

    /**
     * Resolve a word:{slug} card for reviews from past packs or the ladder.
     *
     * @return array<string, mixed>|null
     */
    public function resolveWordCard(ChildProfile $child, string $key): ?array
    {
        if (! str_starts_with($key, 'word:')) {
            return null;
        }

        $rows = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('purpose', 'daily_word_pack')
            ->orderByDesc('business_date')
            ->limit(60)
            ->get(['items']);

        foreach ($rows as $row) {
            $items = json_decode((string) $row->items, true);
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $card = $this->asDailyWordCard($item);
                if ($card !== null && ($card['key'] ?? '') === $key) {
                    return $card;
                }
            }
        }

        $slug = substr($key, strlen('word:'));
        $word = str_replace('-', ' ', $slug);
        $entry = $this->ladderEntry($word) ?? $this->ladderEntry(str_replace('-', '', $slug));
        if ($entry === null) {
            return null;
        }

        return $this->asDailyWordCard($entry);
    }

    public function newWordsCompletedToday(ChildProfile $child, string $date): int
    {
        return (int) DB::table('english_learning_progress')
            ->where('child_id', $child->id)
            ->where('completed_date', $date)
            ->where('item_key', 'like', 'word:%')
            ->count();
    }

    public function dailyWordGoal(ChildProfile $child, string $date): int
    {
        $cards = $this->dailyWordPack($child, $date);
        $completed = $this->newWordsCompletedToday($child, $date);
        $done = DB::table('english_learning_progress')
            ->where('child_id', $child->id)->where('completed_date', $date)
            ->whereIn('item_key', array_column($cards, 'key'))->pluck('item_key')->all();
        $done = array_fill_keys($done, true);
        $pending = 0;
        foreach ($cards as $card) {
            if (! isset($done[$card['key']])) {
                $pending++;
            }
        }

        return min(self::NEW_WORDS_DAILY_GOAL, $completed + $pending);
    }

    /**
     * Today's AI word-pack rows with done / learning / pending status for the kid record view.
     *
     * @return list<array<string, mixed>>
     */
    public function newWordsTodayRecords(ChildProfile $child, string $date): array
    {
        $pack = $this->dailyWordPack($child, $date);
        $progress = DB::table('english_learning_progress')
            ->where('child_id', $child->id)
            ->where('item_key', 'like', 'word:%')
            ->get()
            ->keyBy('item_key');

        $out = [];
        foreach ($pack as $card) {
            $key = (string) ($card['key'] ?? '');
            $row = $progress->get($key);
            $status = 'pending';
            if ($row !== null && $row->completed_date === $date) {
                $status = 'done';
            } elseif ($row !== null) {
                $status = 'learning';
            }
            $out[] = [
                'key' => $key,
                'text' => (string) ($card['text'] ?? ''),
                'meaning' => (string) ($card['meaning'] ?? ''),
                'emoji' => (string) ($card['emoji'] ?? '🔤'),
                'ipa' => (string) ($card['ipa'] ?? ''),
                'pos' => (string) ($card['pos'] ?? ''),
                'status' => $status,
                'collected' => ! empty($card['collected']),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function asDailyWordCard(array $raw): ?array
    {
        if (preg_match('/^[A-Za-z]+(?:[\x27-][A-Za-z]+)*$/', trim((string) ($raw['text'] ?? ''))) !== 1) {
            return null;
        }
        $band = 6;
        $card = $this->gate->probeCard($raw, [], $band);
        if ($card === null) {
            // Already sanitized probe-shaped card from store.
            $text = $this->gate->expression((string) ($raw['text'] ?? ''));
            $meaning = trim((string) ($raw['meaning'] ?? ''));
            $options = $raw['options'] ?? null;
            if ($text === '' || $meaning === '' || ! is_array($options) || count($options) < 2) {
                return null;
            }
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
            if ($slug === '') {
                return null;
            }
            $card = [
                'key' => 'probe:'.$slug,
                'theme' => '今日新词',
                'kind' => 'word',
                'text' => $text,
                'meaning' => $meaning,
                'emoji' => (string) ($raw['emoji'] ?? $this->gate->emojiFor($text, 'word')),
                'ipa' => (string) ($raw['ipa'] ?? ''),
                'pos' => (string) ($raw['pos'] ?? ''),
                'options' => array_values(array_slice($options, 0, 3)),
                'review' => false,
                'stage' => 0,
                'probe' => false,
            ];
        }

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $card['text'])), '-');
        if ($slug === '') {
            return null;
        }

        return [
            ...$card,
            'key' => 'word:'.$slug,
            'theme' => '今日新词',
            'kind' => 'word',
            'probe' => false,
            'challenge' => false,
            'spelling' => false,
            'daily_word' => true,
            'collected' => ! empty($raw['collected']),
            'review' => false,
            'stage' => 0,
        ];
    }

    /**
     * Words already in today's pack, lowercased, for highlighting tale tokens.
     *
     * @return list<string>
     */
    public function packWordTexts(ChildProfile $child, string $date): array
    {
        $words = [];
        foreach ($this->dailyWordPack($child, $date) as $card) {
            $text = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
            if ($text !== '' && ! str_contains($text, ' ')) {
                $words[] = $text;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * Append one fairy-tale word onto today's pack so it can be practiced as a new word.
     *
     * @param  array<string, mixed>  $tale
     * @return array{word: string, added: bool, already: bool, learned: bool, key: string, meaning: string, pack_words: list<string>}
     */
    public function appendCollectedDailyWord(ChildProfile $child, string $date, string $word, array $tale): array
    {
        $word = $this->normalizeTaleWord($word);
        if ($word === '' || isset($this->taleWordSkip()[$word]) || preg_match('/^[a-z]{2,24}$/', $word) !== 1) {
            throw ValidationException::withMessages([
                'word' => '请点一个想学的英文单词。',
            ]);
        }
        if (! $this->taleContainsWord($tale, $word)) {
            throw ValidationException::withMessages([
                'word' => '这个词不在这篇童话里。',
            ]);
        }

        $this->dailyWordPack($child, $date);
        $record = $this->coach->findRecord($child, 'daily_word_pack', $date);
        if ($record === null) {
            throw ValidationException::withMessages([
                'word' => '今天的新词还在准备，请稍后再试。',
            ]);
        }

        $cards = $this->cardsFromDailyWordRecord($record);
        foreach ($cards as $card) {
            if (strtolower((string) ($card['text'] ?? '')) !== $word) {
                continue;
            }
            $learned = DB::table('english_learning_progress')
                ->where('child_id', $child->id)
                ->where('item_key', (string) ($card['key'] ?? ''))
                ->where('completed_date', $date)
                ->exists();

            return [
                'word' => $word,
                'added' => false,
                'already' => true,
                'learned' => $learned,
                'key' => (string) ($card['key'] ?? ''),
                'meaning' => (string) ($card['meaning'] ?? ''),
                'pack_words' => $this->packWordTexts($child, $date),
            ];
        }

        $raw = $this->rawForCollectedWord($child, $word);
        $raw['collected'] = true;
        $card = $this->asDailyWordCard($raw);
        if ($card === null) {
            throw ValidationException::withMessages([
                'word' => '这个词暂时还不能加入，请换一个。',
            ]);
        }
        $card['collected'] = true;

        $stored = json_decode((string) $record->items, true);
        $merged = is_array($stored) ? array_values($stored) : [];
        $merged[] = $card;
        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            // mixed so a later local-pack retry cannot drop the word the child just saved.
            'status' => 'mixed',
            'profile_snapshot' => json_encode(array_merge(
                json_decode((string) ($record->profile_snapshot ?? '{}'), true) ?: [],
                ['pack_size' => count($merged), 'collected_word' => $word],
            ), JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'word' => $word,
            'added' => true,
            'already' => false,
            'learned' => false,
            'key' => (string) $card['key'],
            'meaning' => (string) ($card['meaning'] ?? ''),
            'pack_words' => $this->packWordTexts($child, $date),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function taleWordSkip(): array
    {
        return array_fill_keys([
            'a', 'an', 'the', 'is', 'am', 'are', 'was', 'were', 'be', 'to', 'of', 'and', 'or',
            'in', 'on', 'at', 'my', 'your', 'i', 'you', 'we', 'he', 'she', 'it', 'they',
            'this', 'that', 'do', 'does', 'did', 'can', 'will', 'her', 'his', 'him', 'me',
            'us', 'them', 'our', 'their', 'its',
        ], true);
    }

    private function normalizeTaleWord(string $word): string
    {
        $word = strtolower(trim(str_replace('’', "'", $word)));
        $word = preg_replace('/^[^a-z]+|[^a-z]+$/i', '', $word) ?? '';
        $word = preg_replace("/'s$/", '', $word) ?? $word;

        return strtolower($word);
    }

    /**
     * @param  array<string, mixed>  $tale
     */
    private function taleContainsWord(array $tale, string $word): bool
    {
        $lines = [(string) ($tale['title_en'] ?? '')];
        foreach ($tale['en_lines'] ?? [] as $line) {
            $lines[] = (string) $line;
        }
        $blob = strtolower(str_replace('’', "'", implode(' ', $lines)));

        return preg_match('/(?<![a-z])'.preg_quote($word, '/').'(?![a-z])/', $blob) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawForCollectedWord(ChildProfile $child, string $word): array
    {
        $entry = $this->ladderEntry($word);
        if (is_array($entry) && ! str_contains((string) ($entry['text'] ?? ''), ' ')) {
            $entry['kind'] = 'word';
            $entry['collected'] = true;

            return $entry;
        }

        $gloss = $this->storyGloss($word);
        if ($gloss !== null) {
            return $gloss;
        }

        $fromAi = $this->glossCollectedWord($child, $word);
        if ($fromAi !== null) {
            $fromAi['collected'] = true;

            return $fromAi;
        }

        return [
            'kind' => 'word',
            'text' => $word,
            'meaning' => '新词',
            'emoji' => $this->gate->emojiFor($word, 'word'),
            'ipa' => '',
            'pos' => '',
            'options' => ['新词', '旧词', '短语'],
            'collected' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function glossCollectedWord(ChildProfile $child, string $word): ?array
    {
        $reply = $this->coach->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'Define one English word for a child age 5–8. Reply JSON only: {"items":[{"kind":"word","text":"forest","meaning":"森林","emoji":"🌲","ipa":"/ˈfɔːrɪst/","pos":"n.","options":["森林","海洋","沙漠"]}]}. text must be exactly the given word. meaning is simplified Chinese. Exactly 3 different Chinese options including the meaning. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode(['word' => $word], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        if ($reply === null) {
            return null;
        }

        $cards = $this->sanitizeDailyWordItems($reply['content'], [], 1, 6);
        $card = $cards[0] ?? null;
        if (! is_array($card)) {
            return null;
        }
        if (strtolower($this->gate->expression((string) ($card['text'] ?? ''))) !== $word) {
            return null;
        }

        return $card;
    }

    /**
     * Local meanings for words in the offline fairy-tale fallback.
     *
     * @return array<string, mixed>|null
     */
    private function storyGloss(string $word): ?array
    {
        $map = [
            'sweet' => ['甜美的', '🍬', 'adj.', ['甜美的', '酸的', '苦的']],
            'girl' => ['女孩', '👧', 'n.', ['女孩', '男孩', '老师']],
            'called' => ['叫做', '📛', 'v.', ['叫做', '忘记', '看见']],
            'little' => ['小的', '🐭', 'adj.', ['小的', '大的', '高的']],
            'red' => ['红色', '🔴', 'adj.', ['红色', '蓝色', '绿色']],
            'riding' => ['骑行', '🚴', 'v.', ['骑行', '游泳', '睡觉']],
            'hood' => ['兜帽', '🧢', 'n.', ['兜帽', '鞋子', '手套']],
            'day' => ['一天', '📅', 'n.', ['一天', '一年', '一夜']],
            'mother' => ['妈妈', '👩', 'n.', ['妈妈', '爸爸', '奶奶']],
            'asked' => ['请求', '🙋', 'v.', ['请求', '拒绝', '忘记']],
            'take' => ['带去', '🎒', 'v.', ['带去', '留下', '扔掉']],
            'treat' => ['点心', '🍪', 'n.', ['点心', '蔬菜', '玩具']],
            'treats' => ['点心', '🍪', 'n.', ['点心', '蔬菜', '玩具']],
            'grandma' => ['奶奶', '👵', 'n.', ['奶奶', '妈妈', '姐姐']],
            'walk' => ['走路', '🚶', 'v.', ['走路', '飞翔', '游泳']],
            'walked' => ['走路', '🚶', 'v.', ['走路', '飞翔', '游泳']],
            'through' => ['穿过', '🌲', 'prep.', ['穿过', '离开', '靠近']],
            'forest' => ['森林', '🌲', 'n.', ['森林', '海洋', '沙漠']],
            'saw' => ['看见', '👀', 'v.', ['看见', '听见', '忘记']],
            'pretty' => ['漂亮的', '🌸', 'adj.', ['漂亮的', '丑陋的', '吵闹的']],
            'flower' => ['花', '🌸', 'n.', ['花', '树', '草']],
            'flowers' => ['花', '🌸', 'n.', ['花', '树', '草']],
            'pick' => ['采摘', '🌷', 'v.', ['采摘', '扔掉', '种植']],
            'picked' => ['采摘', '🌷', 'v.', ['采摘', '扔掉', '种植']],
            'make' => ['使变得', '✨', 'v.', ['使变得', '破坏', '隐藏']],
            'happy' => ['开心的', '😊', 'adj.', ['开心的', '伤心的', '生气的']],
            'arrive' => ['到达', '🏠', 'v.', ['到达', '离开', '迷路']],
            'arrived' => ['到达', '🏠', 'v.', ['到达', '离开', '迷路']],
            'safely' => ['安全地', '🛟', 'adv.', ['安全地', '匆忙地', '悄悄地']],
            'safe' => ['安全的', '🛟', 'adj.', ['安全的', '危险的', '吵闹的']],
            'house' => ['房子', '🏠', 'n.', ['房子', '学校', '商店']],
            'smile' => ['微笑', '😊', 'v.', ['微笑', '哭泣', '喊叫']],
            'smiled' => ['微笑', '😊', 'v.', ['微笑', '哭泣', '喊叫']],
            'said' => ['说', '💬', 'v.', ['说', '听', '跑']],
            'thank' => ['感谢', '🙏', 'v.', ['感谢', '责备', '忘记']],
            'ate' => ['吃', '🍽️', 'v.', ['吃', '喝', '睡']],
            'together' => ['一起', '🤝', 'adv.', ['一起', '分开', '独自']],
            'felt' => ['感觉', '💭', 'v.', ['感觉', '忘记', '看见']],
            'promise' => ['承诺', '🤝', 'n.', ['承诺', '借口', '玩笑']],
            'promised' => ['答应', '🤝', 'v.', ['答应', '拒绝', '忘记']],
            'stay' => ['停留', '🛑', 'v.', ['停留', '离开', '奔跑']],
            'path' => ['小路', '🛤️', 'n.', ['小路', '河流', '桥梁']],
            'next' => ['下次', '⏭️', 'adj.', ['下次', '上次', '每天']],
            'time' => ['时候', '⏰', 'n.', ['时候', '地点', '名字']],
            'once' => ['从前', '📖', 'adv.', ['从前', '现在', '以后']],
            'there' => ['那里', '👉', 'adv.', ['那里', '这里', '哪里']],
            'then' => ['然后', '➡️', 'adv.', ['然后', '但是', '因为']],
            'some' => ['一些', '✨', 'adj.', ['一些', '没有', '全部']],
        ];
        $row = $map[$word] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'kind' => 'word',
            'text' => $word,
            'meaning' => $row[0],
            'emoji' => $row[1],
            'ipa' => '',
            'pos' => $row[2],
            'options' => $row[3],
            'collected' => true,
        ];
    }

    private function createDailyWordPack(ChildProfile $child, string $date): object
    {
        $profile = $this->profile($child);
        $band = $this->practiceBand($child);
        $snapshot = [
            'band' => $band,
            'path' => $profile['path'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($profile['known_words'], -40),
            'missed_words' => array_slice($profile['missed_words'], -15),
            'learning_words' => array_slice($profile['learning_words'], -15),
            'goal' => self::NEW_WORDS_DAILY_GOAL,
        ];
        $skillFocus = $this->skillPilot->focus($profile['known_words']);
        $snapshot['skill_pilot'] = [
            'topic_id' => $skillFocus['topic_id'] ?? null,
            'focus' => $skillFocus['focus'] ?? null,
            'candidate_words' => $skillFocus['candidate_words'] ?? [],
        ];

        $avoid = $this->dailyWordAvoidList($child, $date, []);

        $fromAi = [];
        $provider = null;
        $model = null;
        $system = 'You pick exactly '.self::NEW_WORDS_DAILY_GOAL.' English vocabulary words for a child to learn today. Use the estimated vocabulary size and band in the user JSON. Prefer words slightly above what they already know; avoid known_words and learning_words. The skill_pilot is an optional teaching focus: prefer suitable candidate_words when they fit the child, but do not force them or repeat known words. Reply JSON only: {"items":[{"kind":"word","text":"bird","meaning":"鸟","emoji":"🐦","ipa":"/bɜːrd/","pos":"n.","options":["鸟","鱼","树"]}]}. Each item must be a single word (no spaces), simplified Chinese meaning, IPA, pos, emoji, and exactly 3 Chinese options including the meaning. No personal data.';
        $reply = $this->coach->chatJson($child, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)],
        ]);
        if ($reply !== null) {
            $provider = $reply['provider'];
            $model = $reply['model'];
            $fromAi = $this->sanitizeDailyWordItems($reply['content'], $avoid, self::NEW_WORDS_DAILY_GOAL, $band);
        }

        $aiCount = count($fromAi);
        $items = $this->fillDailyWordPackToGoal($fromAi, self::NEW_WORDS_DAILY_GOAL, $avoid, max(1, $band));
        $status = $aiCount === 0 ? 'local' : ($aiCount >= count($items) ? 'ai' : 'mixed');
        $snapshot['pack_size'] = count($items);

        return $this->coach->storeRecord(
            $child,
            'daily_word_pack',
            $date,
            $snapshot,
            $items,
            $status,
            $provider ?? $this->coach->selectedProvider($child),
            $model,
        );
    }

    /**
     * Hard requirement: never persist fewer than the daily goal.
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, true>  $avoid
     * @return list<array<string, mixed>>
     */
    private function fillDailyWordPackToGoal(array $cards, int $goal, array $avoid, int $band): array
    {
        $items = $this->fillDailyWordsFromLadder($cards, $goal, $avoid, $band);
        if (count($items) >= $goal) {
            return array_slice($items, 0, $goal);
        }

        // Widen to easier bands, then rotate the ladder, before relaxing the avoid list.
        foreach ([max(1, $band - 1), 1] as $fallbackBand) {
            $items = $this->fillDailyWordsFromLadder($items, $goal, $avoid, $fallbackBand);
            if (count($items) >= $goal) {
                return array_slice($items, 0, $goal);
            }
        }

        for ($rotate = 1; $rotate <= 5 && count($items) < $goal; $rotate++) {
            $items = $this->fillDailyWordsFromLadder($items, $goal, $avoid, 1, $rotate);
        }
        if (count($items) >= $goal) {
            return array_slice($items, 0, $goal);
        }

        // A short pack is preferable to presenting an already learned word as new.
        return array_slice($items, 0, $goal);
    }

    /**
     * @param  list<array<string, mixed>>  $existingCards
     * @return array<string, true>
     */
    private function dailyWordAvoidList(ChildProfile $child, string $date, array $existingCards): array
    {
        $profile = $this->profile($child);
        $avoid = [];
        foreach ([...$profile['known_words'], ...$profile['learning_words']] as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }
        $previousKeys = DB::table('english_learning_progress')
            ->where('child_id', $child->id)->where('item_key', 'like', 'word:%')->pluck('item_key');
        foreach ($previousKeys as $key) {
            $avoid[str_replace('-', ' ', substr((string) $key, strlen('word:')))] = true;
        }
        foreach ($existingCards as $card) {
            $expression = $this->gate->expression((string) ($card['text'] ?? ''));
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }
        $doneToday = DB::table('english_learning_progress')
            ->where('child_id', $child->id)
            ->where('completed_date', $date)
            ->where('item_key', 'like', 'word:%')
            ->pluck('item_key');
        foreach ($doneToday as $itemKey) {
            $slug = substr((string) $itemKey, strlen('word:'));
            $word = str_replace('-', ' ', $slug);
            if ($word !== '') {
                $avoid[strtolower($word)] = true;
            }
        }

        return $avoid;
    }

    /**
     * @param  array<string, true>  $avoid
     * @return list<array<string, mixed>>
     */
    private function sanitizeDailyWordItems(string $content, array $avoid, int $slots, int $band): array
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
            $raw['kind'] = 'word';
            $card = $this->gate->probeCard($raw, $avoid, max(1, $band));
            if ($card === null) {
                continue;
            }
            if (str_contains((string) $card['text'], ' ')) {
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
    private function fillDailyWordsFromLadder(array $cards, int $slots, array $avoid, int $band, int $rotate = 0): array
    {
        foreach ($cards as $card) {
            $expression = $this->gate->expression((string) ($card['text'] ?? ''));
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }

        $filled = $this->fillFromLadder($cards, $slots + 12, $avoid, $band, $rotate);
        $out = [];
        foreach ($filled as $card) {
            if (($card['kind'] ?? '') !== 'word' || str_contains((string) ($card['text'] ?? ''), ' ')) {
                continue;
            }
            $norm = strtolower($this->gate->expression((string) $card['text']));
            if ($norm === '' || $this->cardInList($out, $norm)) {
                continue;
            }
            $out[] = $card;
            if (count($out) >= $slots) {
                break;
            }
        }

        return array_slice($out, 0, $slots);
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private function cardInList(array $cards, string $norm): bool
    {
        foreach ($cards as $card) {
            if (strtolower($this->gate->expression((string) ($card['text'] ?? ''))) === $norm) {
                return true;
            }
        }

        return false;
    }

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
            throw ValidationException::withMessages([
                'word' => '请点选一个英文单词。',
            ]);
        }
        if (mb_strlen($word) > 24) {
            throw ValidationException::withMessages([
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
     * Kid tapped "I don't know" on a spelling card: stop spelling it today and
     * queue the same word as a vocabulary probe (词汇小测试).
     *
     * @param  array<string, mixed>  $spellItem
     */
    public function recordSpellingSkip(ChildProfile $child, array $spellItem, string $date): void
    {
        $word = $this->gate->expression((string) ($spellItem['text'] ?? ''));
        if ($word === '' || str_contains($word, ' ')) {
            return;
        }

        $row = $this->profileRow($child->id);
        $known = $this->decodeList($row->known_words ?? null);
        $missed = $this->decodeList($row->missed_words ?? null);
        $learning = $this->decodeList($row->learning_words ?? null);
        $missed[] = $word;
        // Prefer vocabulary probes over more spelling for this word.
        $learning = array_values(array_filter($learning, fn ($item) => $item !== $word));
        $known = array_values(array_filter($known, fn ($item) => $item !== $word));
        $missed = array_slice(array_values(array_unique($missed)), -40);
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
        } else {
            DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->update($payload);
        }

        $this->removeFromSpellingDay($child, $word, $date);
        $this->setWordMastery($child, $word, [
            'mc_correct' => 0,
            'phase' => 'mc',
        ]);
        // Rematch as 词汇小测试 — append (never unshift) so yellow/cloud cannot monopolize the front.
        $this->queueProbeForWord($child, $spellItem, $date);
        $this->coach->discardDayRecords($child, $date, ['session_plan']);
    }

    /**
     * Word probe (英选中): need PROBE_MC_GOAL corrects, then promote to spelling.
     * Spelling correct later marks the word known / done.
     *
     * @param  array<string, mixed>  $item
     * @return array{probe_corrects: int, probe_goal: int, promoted_to_spelling: bool, mastery_phase: string}
     */
    public function recordWordProbeAnswer(ChildProfile $child, array $item, bool $correct, string $date): array
    {
        $word = $this->gate->expression((string) ($item['text'] ?? ''));
        $goal = self::PROBE_MC_GOAL;
        if ($word === '' || str_contains($word, ' ')) {
            return [
                'probe_corrects' => 0,
                'probe_goal' => $goal,
                'promoted_to_spelling' => false,
                'mastery_phase' => 'mc',
            ];
        }

        if (! $correct) {
            $this->observe($child, $word, false);
            $this->setWordMastery($child, $word, [
                'mc_correct' => 0,
                'phase' => 'mc',
            ]);
            $this->queueProbeForWord($child, $item, $date);
            $this->coach->discardDayRecords($child, $date, ['session_plan']);

            return [
                'probe_corrects' => 0,
                'probe_goal' => $goal,
                'promoted_to_spelling' => false,
                'mastery_phase' => 'mc',
            ];
        }

        $state = $this->wordMastery($child, $word);
        $corrects = min($goal, (int) ($state['mc_correct'] ?? 0) + 1);
        if ($corrects >= $goal) {
            $this->setWordMastery($child, $word, [
                'mc_correct' => $goal,
                'phase' => 'spell',
            ]);
            $this->ensureInLearningWords($child, $word);
            // Spelling skip today already retired this word — keep phase=spell for tomorrow,
            // but do not bounce MC → spell → skip → MC in the same session.
            $promoted = ! $this->spellingSkippedToday($child, $word, $date);
            if ($promoted) {
                $this->enqueueSpellingWord($child, $word, $date);
            }
            $this->coach->discardDayRecords($child, $date, ['session_plan']);

            return [
                'probe_corrects' => $goal,
                'probe_goal' => $goal,
                'promoted_to_spelling' => $promoted,
                'mastery_phase' => 'spell',
            ];
        }

        $this->setWordMastery($child, $word, [
            'mc_correct' => $corrects,
            'phase' => 'mc',
        ]);
        // Stay on 英选中 until PROBE_MC_GOAL — queue rematch at the back so other words stay first.
        $this->queueProbeForWord($child, $item, $date);
        $this->coach->discardDayRecords($child, $date, ['session_plan']);

        return [
            'probe_corrects' => $corrects,
            'probe_goal' => $goal,
            'promoted_to_spelling' => false,
            'mastery_phase' => 'mc',
        ];
    }

    /**
     * Spelling correct finishes the vocab path for a word.
     *
     * @param  array<string, mixed>  $item
     */
    public function completeSpelling(ChildProfile $child, array $item, string $date): void
    {
        $word = $this->gate->expression((string) ($item['text'] ?? ''));
        if ($word === '') {
            return;
        }
        $this->observe($child, $word, true);
        $this->setWordMastery($child, $word, [
            'mc_correct' => self::PROBE_MC_GOAL,
            'phase' => 'done',
        ]);
        $this->removeFromSpellingDay($child, $word, $date);
    }

    /**
     * @return array{mc_correct: int, phase: string}
     */
    public function wordMastery(ChildProfile $child, string $word): array
    {
        $word = $this->gate->expression($word);
        $all = $this->masteryMap($child);
        $row = $all[strtolower($word)] ?? null;
        if (! is_array($row)) {
            return ['mc_correct' => 0, 'phase' => 'mc'];
        }

        $phase = (string) ($row['phase'] ?? 'mc');
        if (! in_array($phase, ['mc', 'spell', 'done'], true)) {
            $phase = 'mc';
        }

        return [
            'mc_correct' => max(0, min(self::PROBE_MC_GOAL, (int) ($row['mc_correct'] ?? 0))),
            'phase' => $phase,
        ];
    }

    /**
     * @return list<string>
     */
    public function wordsInMasteryPhase(ChildProfile $child, string $phase): array
    {
        $out = [];
        foreach ($this->masteryMap($child) as $norm => $row) {
            if (! is_array($row) || (string) ($row['phase'] ?? '') !== $phase) {
                continue;
            }
            $text = trim((string) ($row['text'] ?? $norm));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spellItem
     */
    private function queueProbeForWord(ChildProfile $child, array $spellItem, string $date): void
    {
        $word = $this->gate->expression((string) ($spellItem['text'] ?? ''));
        if ($word === '') {
            return;
        }

        $entry = $this->ladderEntry($word) ?? [];
        $meaning = trim((string) ($entry['meaning'] ?? $spellItem['meaning'] ?? ''));
        if ($meaning === '' || $meaning === '拼写这个单词') {
            $meaning = (string) ($spellItem['meaning'] ?? '');
        }
        if ($meaning === '' || $meaning === '拼写这个单词') {
            return;
        }

        $raw = [
            'kind' => 'word',
            'text' => $word,
            'meaning' => $meaning,
            'emoji' => (string) ($entry['emoji'] ?? $spellItem['emoji'] ?? ''),
            'ipa' => (string) ($entry['ipa'] ?? $spellItem['ipa'] ?? ''),
            'pos' => (string) ($entry['pos'] ?? $spellItem['pos'] ?? ''),
            'options' => is_array($entry['options'] ?? null) ? $entry['options'] : [],
        ];
        // High band so fitsBand does not drop a word the child already met in spelling.
        $card = $this->gate->probeCard($raw, [], 6);
        if ($card === null) {
            return;
        }

        $profile = $this->profile($child);
        $band = $this->practiceBand($child);
        $record = $this->coach->findRecord($child, 'vocabulary_probe', $date);
        if ($record === null) {
            $this->coach->storeRecord(
                $child,
                'vocabulary_probe',
                $date,
                [
                    'band' => $band,
                    'path' => $profile['path'],
                    'estimated_size' => $profile['estimated_size'],
                    'from_spelling_skip' => true,
                    'count' => 1,
                ],
                [$card],
                'local',
            );

            return;
        }

        $existing = json_decode((string) $record->items, true);
        if (! is_array($existing)) {
            $existing = [];
        }

        $answered = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('item_key', 'like', 'probe:%')
            ->pluck('item_key')
            ->all();
        $answeredLookup = [];
        foreach ($answered as $key) {
            $answeredLookup[(string) $key] = true;
        }

        $seenKeys = [];
        $queuedNorm = [];
        foreach ($existing as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            if ($key !== '') {
                $seenKeys[$key] = true;
            }
            $expression = $this->gate->expression((string) ($item['text'] ?? ''));
            if ($expression === '') {
                continue;
            }
            $norm = strtolower($expression);
            if ($key !== '' && ! isset($answeredLookup[$key])) {
                $queuedNorm[$norm] = true;
            }
        }
        foreach ($answeredLookup as $key => $_) {
            $seenKeys[$key] = true;
        }

        if (isset($queuedNorm[strtolower($word)])) {
            return;
        }

        $card['key'] = $this->uniqueProbeKey((string) $card['key'], $seenKeys);
        // Append — never unshift. Front-loading rematches made local band-3 packs
        // (yellow ↔ cloud) feel like an infinite two-word loop with the kid UI.
        $existing[] = $card;

        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode(array_values($existing), JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function spellingSkippedToday(ChildProfile $child, string $word, string $date): bool
    {
        $slug = $this->slug($word);
        if ($slug === '') {
            return false;
        }

        return DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('item_key', 'spell:'.$slug.':skip')
            ->exists();
    }

    private function removeFromSpellingDay(ChildProfile $child, string $word, string $date): void
    {
        $record = $this->coach->findRecord($child, 'spelling_day', $date);
        if ($record === null) {
            return;
        }
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return;
        }
        $want = strtolower($word);
        $filtered = [];
        foreach ($items as $item) {
            $text = is_string($item) ? $item : (is_array($item) ? (string) ($item['text'] ?? '') : '');
            if ($text === '' || strtolower($text) === $want) {
                continue;
            }
            $filtered[] = $item;
        }
        if (count($filtered) === count($items)) {
            return;
        }
        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode(array_values($filtered), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array<string, array{text: string, mc_correct: int, phase: string}>
     */
    private function masteryMap(ChildProfile $child): array
    {
        $record = $this->coach->findRecord($child, self::MASTERY_PURPOSE, self::MASTERY_DATE);
        if ($record === null) {
            return [];
        }
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return [];
        }
        $map = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->gate->expression((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $phase = (string) ($item['phase'] ?? 'mc');
            if (! in_array($phase, ['mc', 'spell', 'done'], true)) {
                $phase = 'mc';
            }
            $map[strtolower($text)] = [
                'text' => $text,
                'mc_correct' => max(0, min(self::PROBE_MC_GOAL, (int) ($item['mc_correct'] ?? 0))),
                'phase' => $phase,
            ];
        }

        return $map;
    }

    /**
     * @param  array{mc_correct?: int, phase?: string}  $state
     */
    private function setWordMastery(ChildProfile $child, string $word, array $state): void
    {
        $word = $this->gate->expression($word);
        if ($word === '') {
            return;
        }
        $map = $this->masteryMap($child);
        $map[strtolower($word)] = [
            'text' => $word,
            'mc_correct' => max(0, min(self::PROBE_MC_GOAL, (int) ($state['mc_correct'] ?? 0))),
            'phase' => in_array(($state['phase'] ?? 'mc'), ['mc', 'spell', 'done'], true)
                ? (string) $state['phase']
                : 'mc',
        ];
        $items = array_values($map);
        $record = $this->coach->findRecord($child, self::MASTERY_PURPOSE, self::MASTERY_DATE);
        if ($record === null) {
            $this->coach->storeRecord(
                $child,
                self::MASTERY_PURPOSE,
                self::MASTERY_DATE,
                ['kind' => 'mastery_ledger'],
                $items,
                'local',
            );

            return;
        }
        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'profile_snapshot' => json_encode(['kind' => 'mastery_ledger', 'count' => count($items)], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function ensureInLearningWords(ChildProfile $child, string $word): void
    {
        $word = $this->gate->expression($word);
        if ($word === '' || str_contains($word, ' ')) {
            return;
        }
        $row = $this->profileRow($child->id);
        $learning = $this->decodeList($row->learning_words ?? null);
        if (in_array($word, $learning, true)) {
            return;
        }
        $learning[] = $word;
        $learning = array_slice(array_values(array_unique($learning)), -60);
        $payload = [
            'learning_words' => json_encode($learning, JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->clock->now(),
        ];
        if ($row === null) {
            DB::table('english_vocabulary_profiles')->insert($payload + [
                'child_id' => $child->id,
                'estimated_size' => 0,
                'band' => 1,
                'path' => self::PATH_STANDARD,
                'known_words' => json_encode([], JSON_UNESCAPED_UNICODE),
                'missed_words' => json_encode([], JSON_UNESCAPED_UNICODE),
            ]);

            return;
        }
        DB::table('english_vocabulary_profiles')->where('child_id', $child->id)->update($payload);
    }

    private function enqueueSpellingWord(ChildProfile $child, string $word, string $date): void
    {
        $word = $this->gate->expression($word);
        if ($word === '' || str_contains($word, ' ')) {
            return;
        }
        if ($this->spellingSkippedToday($child, $word, $date)) {
            return;
        }
        $record = $this->coach->findRecord($child, 'spelling_day', $date);
        if ($record === null) {
            $this->coach->storeRecord(
                $child,
                'spelling_day',
                $date,
                ['count' => 1, 'limit' => self::SPELLING_DAILY_LIMIT, 'from_probe' => true],
                [$word],
                'local',
            );

            return;
        }
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            $items = [];
        }
        foreach ($items as $item) {
            $text = is_string($item) ? $item : (is_array($item) ? (string) ($item['text'] ?? '') : '');
            if (strtolower($text) === strtolower($word)) {
                return;
            }
        }
        array_unshift($items, $word);
        $items = array_slice(array_values($items), 0, max(self::SPELLING_DAILY_LIMIT, count($items)));
        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Spelling fill-ins: up to SPELLING_DAILY_LIMIT random learned words per day.
     *
     * @param  list<string>  $answeredKeys  correctly spelled or skipped keys for today
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
        // Words that passed 英选中 ×2 come first; then learning/known fills (skip mid-MC words).
        $spellReady = $this->wordsInMasteryPhase($child, 'spell');
        $done = array_fill_keys(array_map('strtolower', $this->wordsInMasteryPhase($child, 'done')), true);
        $pool = [];
        foreach ([...$spellReady, ...$profile['learning_words'], ...$profile['known_words']] as $word) {
            $word = $this->gate->expression((string) $word);
            if ($word === '' || str_contains($word, ' ') || mb_strlen($word) < 2) {
                continue;
            }
            $norm = strtolower($word);
            if (isset($done[$norm])) {
                continue;
            }
            $mastery = $this->wordMastery($child, $word);
            if ($mastery['phase'] === 'mc' && $mastery['mc_correct'] > 0) {
                continue;
            }
            $pool[$norm] = $word;
        }
        $pool = array_values($pool);
        if ($pool === []) {
            return [];
        }

        // Keep spell-ready order at the front; shuffle only the remainder.
        $readyNorms = array_fill_keys(array_map('strtolower', $spellReady), true);
        $ready = [];
        $rest = [];
        foreach ($pool as $word) {
            if (isset($readyNorms[strtolower($word)])) {
                $ready[] = $word;
            } else {
                $rest[] = $word;
            }
        }
        shuffle($rest);
        $picked = array_slice([...$ready, ...$rest], 0, self::SPELLING_DAILY_LIMIT);
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

        // Re-queue English→Chinese rematches after band rebuilds wipe the pool.
        $this->ensureOpenMasteryProbes($child, $date);
        $record = $this->coach->findRecord($child, 'vocabulary_probe', $date) ?? $record;

        $cards = $this->pickProbeCardsForSession($child, $record, $answered, $slots);
        // Another round after finishing a batch — grow the same-day pool without a daily stop.
        if (count($cards) < $slots) {
            $record = $this->appendProbeCards($child, $record, $slots - count($cards), $avoidTexts, $answered, $date);
            $this->ensureOpenMasteryProbes($child, $date);
            $record = $this->coach->findRecord($child, 'vocabulary_probe', $date) ?? $record;
            $cards = $this->pickProbeCardsForSession($child, $record, $answered, $slots);
        }

        return $this->ensureSentenceMix($child, $cards, $slots, $avoidTexts, $answered, $practiceBand, $date);
    }

    /**
     * Words mid-way through 英选中 (1 correct so far) must stay available as probes.
     */
    private function ensureOpenMasteryProbes(ChildProfile $child, string $date): void
    {
        foreach ($this->masteryMap($child) as $row) {
            if (($row['phase'] ?? '') !== 'mc') {
                continue;
            }
            $corrects = (int) ($row['mc_correct'] ?? 0);
            if ($corrects < 1 || $corrects >= self::PROBE_MC_GOAL) {
                continue;
            }
            $text = (string) ($row['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $entry = $this->ladderEntry($text) ?? [];
            $this->queueProbeForWord($child, [
                'text' => $text,
                'meaning' => (string) ($entry['meaning'] ?? ''),
                'emoji' => (string) ($entry['emoji'] ?? ''),
                'ipa' => (string) ($entry['ipa'] ?? ''),
                'pos' => (string) ($entry['pos'] ?? ''),
                'options' => is_array($entry['options'] ?? null) ? $entry['options'] : [],
            ], $date);
        }
    }

    /**
     * Prefer never-seen texts, then tuck a few mid-mastery rematches at the end
     * (never the front) so a 2nd English→Chinese attempt still arrives without
     * sticky yellow↔cloud oscillation.
     *
     * @param  list<string>  $answered
     * @return list<array<string, mixed>>
     */
    private function pickProbeCardsForSession(ChildProfile $child, object $record, array $answered, int $slots): array
    {
        $pool = $this->filterLadderCardsForProbeMastery(
            $child,
            $this->unansweredProbeCards($record, $answered, $slots + 12),
        );
        if ($pool === [] || $slots < 1) {
            return [];
        }

        $fresh = [];
        $rematch = [];
        $answeredTexts = $this->answeredProbeTexts($record, $answered);
        foreach ($pool as $card) {
            $norm = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
            if ($norm !== '' && isset($answeredTexts[$norm])) {
                $rematch[] = $card;
            } else {
                $fresh[] = $card;
            }
        }

        $reserve = min(2, count($rematch), max(0, $slots - 1));
        $out = array_slice($fresh, 0, max(0, $slots - $reserve));
        foreach ($rematch as $card) {
            if (count($out) >= $slots) {
                break;
            }
            $out[] = $card;
        }
        if (count($out) < $slots) {
            foreach ($fresh as $card) {
                if (count($out) >= $slots) {
                    break;
                }
                $norm = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
                if ($this->cardInList($out, $norm)) {
                    continue;
                }
                $out[] = $card;
            }
        }

        return array_slice($out, 0, $slots);
    }

    /**
     * @param  list<string>  $answered
     * @return array<string, true>
     */
    private function answeredProbeTexts(object $record, array $answered): array
    {
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return [];
        }
        $answeredLookup = [];
        foreach ($answered as $key) {
            $answeredLookup[(string) $key] = true;
        }
        $texts = [];
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
                $texts[strtolower($expression)] = true;
            }
        }

        return $texts;
    }

    /**
     * Skip word probes that already moved to spelling / done.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function filterProbeCardsForMastery(ChildProfile $child, array $cards, int $slots): array
    {
        $out = [];
        foreach ($this->filterLadderCardsForProbeMastery($child, $cards) as $card) {
            $out[] = $card;
            if (count($out) >= $slots) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function filterLadderCardsForProbeMastery(ChildProfile $child, array $cards): array
    {
        $mastered = $this->masteredWordLookup($child);
        $out = [];
        foreach ($cards as $card) {
            if (($card['kind'] ?? '') === 'word') {
                $word = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
                if (isset($mastered[$word])) {
                    continue;
                }
                $phase = $this->wordMastery($child, (string) ($card['text'] ?? ''))['phase'];
                if ($phase === 'spell' || $phase === 'done') {
                    continue;
                }
            }
            $out[] = $card;
        }

        return $out;
    }

    /** @return array<string, true> */
    private function masteredWordLookup(ChildProfile $child): array
    {
        $keys = DB::table('english_learning_progress')
            ->where('child_id', $child->id)->where('stage', '>=', 4)
            ->where('item_key', 'like', 'word:%')->pluck('item_key');
        $words = [];
        foreach ($keys as $key) {
            $words[str_replace('-', ' ', substr((string) $key, strlen('word:')))] = true;
        }

        return $words;
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
                // Word mastery needs PROBE_MC_GOAL English→Chinese corrects — keep one rematch.
                $allowRematch = ($item['kind'] ?? '') === 'word'
                    && ! isset($queuedTexts[$norm])
                    && ($this->wordMastery($child, $expression)['phase'] === 'mc')
                    && ($this->wordMastery($child, $expression)['mc_correct'] < self::PROBE_MC_GOAL);
                if ($allowRematch) {
                    $queuedTexts[$norm] = true;
                    $kept[] = $item;

                    continue;
                }
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
        $answeredSentences = [];
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
                if (($item['kind'] ?? '') === 'sentence' || str_contains($expression, ' ')) {
                    $answeredSentences[$norm] = true;
                }
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
        foreach ($profile['known_words'] as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }
        $avoid += $this->masteredWordLookup($child);

        // AI first — local ladder is only a thin fallback so the same 2–3 sentences
        // do not dominate after a short rich-path session.
        $ai = $this->requestProbeItemsFromAi(
            $child,
            [
                'band' => $band,
                'path' => $profile['path'],
                'estimated_size' => $profile['estimated_size'],
                'known_words' => array_slice($profile['known_words'], -30),
                'missed_words' => array_slice($profile['missed_words'], -15),
                'learning_words' => array_slice($profile['learning_words'], -15),
                'avoid_repeat' => array_keys($avoid),
                'answered_sentences_today' => array_keys($answeredSentences),
                'count' => $need + 4,
                'refill' => true,
            ],
            $avoid,
            $need + 4,
            max(1, $band),
            'mixed',
        );
        $extra = $this->filterLadderCardsForProbeMastery($child, $ai['items']);
        $aiAdded = count($extra);

        if (count($extra) < $need) {
            $ladderAvoid = $avoid;
            foreach ($this->wordsInMasteryPhase($child, 'spell') as $text) {
                $ladderAvoid[strtolower($this->gate->expression($text))] = true;
            }
            foreach ($this->wordsInMasteryPhase($child, 'done') as $text) {
                $ladderAvoid[strtolower($this->gate->expression($text))] = true;
            }
            // Never wrap-reuse sentences already answered today — one short-sentence check is enough.
            $ladderAvoid += $answeredSentences;
            $fromLadder = $this->fillFromLadder($extra, $need + 8, $ladderAvoid, max(1, $band));
            $extra = $this->filterLadderCardsForProbeMastery($child, $fromLadder);
        }

        // Word rematches only (mid-MC). Still never recycle answered sentences from the ladder.
        if (count($extra) < $need) {
            $wrapAvoid = $queuedTexts + $answeredSentences;
            foreach ($avoidTexts as $text) {
                $expression = $this->gate->expression((string) $text);
                if ($expression !== '') {
                    $wrapAvoid[strtolower($expression)] = true;
                }
            }
            foreach ([...$this->wordsInMasteryPhase($child, 'spell'), ...$this->wordsInMasteryPhase($child, 'done')] as $text) {
                $wrapAvoid[strtolower($this->gate->expression($text))] = true;
            }
            $wrapped = $this->fillFromLadder($extra, $need + 8, $wrapAvoid, max(1, $band), count($answeredTexts));
            $extra = $this->filterLadderCardsForProbeMastery(
                $child,
                array_values(array_filter(
                    $wrapped,
                    fn ($card) => ($card['kind'] ?? '') !== 'sentence'
                        || ! isset($answeredSentences[strtolower($this->gate->expression((string) ($card['text'] ?? '')))]),
                )),
            );
        }

        // Last resort: ask AI for fresh sentences only (keeps 生活短句 variety without ladder churn).
        $sentenceCount = count(array_filter($extra, fn ($card) => ($card['kind'] ?? '') === 'sentence'));
        if ($sentenceCount < max(1, intdiv($need, 3))) {
            $sentenceAi = $this->requestProbeItemsFromAi(
                $child,
                [
                    'band' => $band,
                    'path' => $profile['path'],
                    'estimated_size' => $profile['estimated_size'],
                    'avoid_repeat' => array_keys($avoid + $queuedTexts),
                    'answered_sentences_today' => array_keys($answeredSentences),
                    'count' => max(2, intdiv($need, 2)),
                    'sentences_only' => true,
                    'refill' => true,
                ],
                $avoid + $queuedTexts,
                max(2, intdiv($need, 2)),
                max(1, $band),
                'sentences',
            );
            foreach ($sentenceAi['items'] as $card) {
                $extra[] = $card;
                $aiAdded++;
            }
            if ($sentenceAi['provider'] !== null) {
                $ai['provider'] = $ai['provider'] ?? $sentenceAi['provider'];
                $ai['model'] = $ai['model'] ?? $sentenceAi['model'];
            }
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
            $norm = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
            if ($norm !== '' && isset($queuedTexts[$norm])) {
                continue;
            }
            // Sentences are one-shot today — never enqueue a second key for the same text.
            if ($norm !== '' && isset($answeredSentences[$norm]) && (($card['kind'] ?? '') === 'sentence' || str_contains((string) ($card['text'] ?? ''), ' '))) {
                continue;
            }
            $key = $this->uniqueProbeKey($baseKey, $seenKeys);
            $card['key'] = $key;
            $existing[] = $card;
            $seenKeys[$key] = true;
            if ($norm !== '') {
                $queuedTexts[$norm] = true;
            }
            $added++;
            if ($added >= $need) {
                break;
            }
        }

        if ($added < 1) {
            return $record;
        }

        $status = (string) ($record->status ?? 'local');
        if ($aiAdded > 0 && $ai['provider'] !== null) {
            $status = $status === 'local' ? 'mixed' : $status;
        }

        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode(array_values($existing), JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'provider' => $ai['provider'] ?? $record->provider,
            'model' => $ai['model'] ?? $record->model,
            'profile_snapshot' => json_encode([
                'band' => $band,
                'path' => $profile['path'],
                'estimated_size' => $profile['estimated_size'],
                'refilled_at' => $date,
                'count' => count($existing),
                'ai_refill' => $aiAdded > 0,
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
     * Keep a short-sentence share. Prefer AI-written sentences persisted into today's
     * probe pool — do not keep re-injecting the same ladder sentence on every plan().
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  list<string>  $avoidTexts
     * @param  list<string>  $answered
     * @return list<array<string, mixed>>
     */
    private function ensureSentenceMix(
        ChildProfile $child,
        array $cards,
        int $slots,
        array $avoidTexts,
        array $answered,
        int $band,
        string $date,
    ): array {
        $wantSentences = $slots >= 3 ? max(1, intdiv($slots, 3)) : ($slots >= 2 ? 1 : 0);
        if ($wantSentences < 1) {
            return array_slice($cards, 0, $slots);
        }

        $sentenceCount = count(array_filter($cards, fn ($card) => ($card['kind'] ?? '') === 'sentence'));
        if ($sentenceCount >= $wantSentences) {
            return array_slice($cards, 0, $slots);
        }

        $need = $wantSentences - $sentenceCount;
        $avoid = [];
        foreach ([...$avoidTexts, ...array_map(fn ($card) => (string) ($card['text'] ?? ''), $cards)] as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }
        $probeRecord = $this->coach->findRecord($child, 'vocabulary_probe', $date);
        $answeredTexts = $probeRecord !== null
            ? $this->answeredProbeTexts($probeRecord, $answered)
            : [];
        $avoid += $answeredTexts;

        $profile = $this->profile($child);
        $ai = $this->requestProbeItemsFromAi(
            $child,
            [
                'band' => $band,
                'path' => $profile['path'],
                'estimated_size' => $profile['estimated_size'],
                'avoid_repeat' => array_keys($avoid),
                'count' => $need + 2,
                'sentences_only' => true,
            ],
            $avoid,
            $need + 2,
            max(1, $band),
            'sentences',
        );
        $extras = array_values(array_filter(
            $ai['items'],
            fn ($card) => ($card['kind'] ?? '') === 'sentence',
        ));

        if (count($extras) < $need) {
            foreach ($this->fillFromLadder([], $need + 6, $avoid, max(1, $band)) as $card) {
                if (($card['kind'] ?? '') !== 'sentence') {
                    continue;
                }
                $norm = strtolower($this->gate->expression((string) ($card['text'] ?? '')));
                if ($norm === '' || isset($avoid[$norm]) || isset($answeredTexts[$norm])) {
                    continue;
                }
                $extras[] = $card;
                $avoid[$norm] = true;
                if (count($extras) >= $need) {
                    break;
                }
            }
        }

        if ($extras !== []) {
            $record = $this->coach->findRecord($child, 'vocabulary_probe', $date);
            if ($record !== null) {
                $existing = json_decode((string) $record->items, true);
                if (! is_array($existing)) {
                    $existing = [];
                }
                $seenKeys = [];
                foreach ($existing as $item) {
                    if (is_array($item) && isset($item['key'])) {
                        $seenKeys[(string) $item['key']] = true;
                    }
                }
                foreach ($answered as $key) {
                    $seenKeys[(string) $key] = true;
                }
                foreach ($extras as $card) {
                    $baseKey = (string) ($card['key'] ?? '');
                    if ($baseKey === '') {
                        continue;
                    }
                    $card['key'] = $this->uniqueProbeKey($baseKey, $seenKeys);
                    $seenKeys[$card['key']] = true;
                    $existing[] = $card;
                }
                $status = (string) ($record->status ?? 'local');
                if ($ai['provider'] !== null && $status === 'local') {
                    $status = 'mixed';
                }
                DB::table('english_ai_records')->where('id', $record->id)->update([
                    'items' => json_encode(array_values($existing), JSON_UNESCAPED_UNICODE),
                    'status' => $status,
                    'provider' => $ai['provider'] ?? $record->provider,
                    'model' => $ai['model'] ?? $record->model,
                ]);
            }
        }

        foreach ($extras as $extra) {
            if (($extra['kind'] ?? '') !== 'sentence') {
                continue;
            }
            if (in_array($extra['key'] ?? '', $answered, true)) {
                continue;
            }
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
            // Keep single words in the learning list for later practice (probes / spelling draws).
            if (! str_contains($expression, ' ')) {
                $learning[] = $expression;
            }
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
        $avoid = [];
        foreach ([...$avoidTexts, ...$known] as $text) {
            $expression = $this->gate->expression((string) $text);
            if ($expression !== '') {
                $avoid[strtolower($expression)] = true;
            }
        }
        $avoid += $this->masteredWordLookup($child);
        $snapshot = [
            'band' => $band,
            'path' => $profile['path'],
            'estimated_size' => $profile['estimated_size'],
            'known_words' => array_slice($known, -30),
            'missed_words' => array_slice($missed, -15),
            'learning_words' => array_slice($profile['learning_words'], -15),
            'avoid_repeat' => array_keys($avoid),
            'count' => $slots,
        ];

        $ai = $this->requestProbeItemsFromAi($child, $snapshot, $avoid, $slots, max(1, $band), 'mixed');
        $fromAi = $ai['items'];
        $aiCount = count($fromAi);
        // Ladder only pads gaps — AI should supply most variety, especially sentences.
        $items = $this->fillFromLadder($fromAi, $slots, $avoid, max(1, $band));
        $status = $aiCount === 0 ? 'local' : ($aiCount >= count($items) ? 'ai' : 'mixed');

        return $this->coach->storeRecord(
            $child,
            'vocabulary_probe',
            $date,
            $snapshot,
            $items,
            $status,
            $ai['provider'] ?? $this->coach->selectedProvider($child),
            $ai['model'],
        );
    }

    /**
     * Ask the selected engine for probe cards. Local ladder is not consulted here.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, true>  $avoid
     * @return array{items: list<array<string, mixed>>, provider: ?string, model: ?string}
     */
    private function requestProbeItemsFromAi(
        ChildProfile $child,
        array $snapshot,
        array $avoid,
        int $slots,
        int $band,
        string $mode = 'mixed',
    ): array {
        if ($slots < 1) {
            return ['items' => [], 'provider' => null, 'model' => null];
        }

        $system = $this->probeAiSystemPrompt($band, $mode);
        $reply = $this->coach->chatJson($child, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)],
        ]);
        if ($reply === null) {
            return ['items' => [], 'provider' => null, 'model' => null];
        }

        $items = $this->sanitizeItems($reply['content'], $avoid, $slots, $band);
        if ($mode === 'sentences') {
            $items = array_values(array_filter(
                $items,
                fn ($card) => ($card['kind'] ?? '') === 'sentence',
            ));
        }

        return [
            'items' => $items,
            'provider' => $reply['provider'] ?? null,
            'model' => $reply['model'] ?? null,
        ];
    }

    private function probeAiSystemPrompt(int $band, string $mode = 'mixed'): string
    {
        $repeatRule = 'Never repeat any string listed in avoid_repeat or answered_sentences_today. Prefer brand-new short sentences each refill — do not recycle ladder classics like "I see a bird." / "I am hungry." / "Good morning!".';
        if ($mode === 'sentences') {
            return $band <= 2
                ? 'You write ONLY easy English short sentences for a young child (ages 5–8). Each sentence at most 5 words. Reply JSON only: {"items":[{"kind":"sentence","text":"I see a cat.","meaning":"我看见一只猫。","emoji":"👀","options":["我看见一只猫。","我想喝水。","这是树。"]}]}. meaning is simplified Chinese. options has exactly 3 Chinese choices including meaning. '.$repeatRule.' No personal data.'
                : 'You write ONLY gentle English short sentences for a child. Max 7 words each. Reply JSON only with sentence items (kind=sentence), simplified Chinese meaning, emoji, exactly 3 Chinese options. '.$repeatRule.' Match band. No personal data.';
        }

        return $band <= 2
            ? 'You write VERY easy English checks for a young child (about ages 5–8, CEFR pre-A1/A1). Use only short everyday words (animal, color, family, food, action) and tiny sentences of at most 5 words. Mix about 2 words : 1 sentence. No hard academic words. Reply JSON only: {"items":[{"kind":"word","text":"bird","meaning":"鸟","emoji":"🐦","ipa":"/bɜːrd/","pos":"n.","options":["鸟","鱼","树"]},{"kind":"sentence","text":"I see a bird.","meaning":"我看见一只鸟。","emoji":"👀","options":["我看见一只鸟。","我想喝水。","这是猫。"]}]}. For words include IPA and pos. Sentences omit ipa/pos. meaning is simplified Chinese. options has exactly 3 different Chinese choices including meaning. '.$repeatRule.' Match band in the user JSON. No personal data.'
            : ($band <= 4
                ? 'You write gentle English checks for a child. Prefer familiar school/home words and short sentences (max 7 words). Avoid obscure academic jargon. Mix about 2 words : 1 sentence. Reply JSON only with the same schema as: {"items":[{"kind":"word","text":"school","meaning":"学校","emoji":"🏫","ipa":"/skuːl/","pos":"n.","options":["学校","公园","商店"]},{"kind":"sentence","text":"I like school.","meaning":"我喜欢学校。","emoji":"🎒","options":["我喜欢学校。","下雨了。","请打开门。"]}]}. meaning is simplified Chinese. options has exactly 3 Chinese choices including meaning. '.$repeatRule.' Match band. No personal data.'
                : 'You write English checks for a child with a growing vocabulary (about CEFR A2). Prefer clear everyday phrases over rare academic words. Mix about 1 word : 1 sentence. Reply JSON only with word/sentence items, IPA/pos for words, simplified Chinese meanings, exactly 3 Chinese options. '.$repeatRule.' Match band. No personal data.');
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
