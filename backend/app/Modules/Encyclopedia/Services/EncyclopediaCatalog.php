<?php

namespace App\Modules\Encyclopedia\Services;

use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use RuntimeException;

/**
 * Loads the vendored Marble Science extract and Chinese overlays for the kid encyclopedia.
 */
class EncyclopediaCatalog
{
    private const DATA_DIR = __DIR__.'/../data/marble';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $topicsById = null;

    /** @var list<array{topicId: string, prerequisiteId: string, strength?: string, reason?: string}>|null */
    private ?array $dependencies = null;

    /**
     * @var array<string, array{
     *     name: string,
     *     body: string,
     *     emoji?: string|null,
     *     reviewed: bool,
     *     words?: list<array{word: string, meaning: string}>,
     *     video?: array{provider: string, id: string}|null,
     *     video_query?: string|null
     * }>|null
     */
    private ?array $overlay = null;

    /** @var array<string, string> */
    private const DOMAIN_EMOJI = [
        'animals-of-the-world' => '🦁',
        'dinosaurs-paleontology' => '🦕',
        'earths-systems' => '🌍',
        'ecosystems-habitats' => '🌳',
        'energy' => '⚡',
        'forces-motion' => '⚽',
        'insects-minibeasts' => '🐛',
        'matter-materials' => '🧊',
        'ocean-life' => '🐋',
        'organisms-life-processes' => '🌱',
        'polar-regions' => '🐧',
        'rainforests' => '🌴',
        'scientific-inquiry' => '🔬',
        'space-exploration' => '🚀',
        'space-systems-earths-history' => '🪐',
        'the-human-body' => '❤️',
        'volcanoes-earthquakes' => '🌋',
        'waves-light-sound' => '💡',
        'weather-climate' => '⛅',
    ];

    /** @var array<string, true> */
    private const WORD_SKIP = [
        'a' => true, 'an' => true, 'the' => true, 'is' => true, 'am' => true, 'are' => true,
        'was' => true, 'were' => true, 'be' => true, 'to' => true, 'of' => true, 'and' => true,
        'or' => true, 'in' => true, 'on' => true, 'at' => true, 'my' => true, 'your' => true,
        'i' => true, 'you' => true, 'we' => true, 'he' => true, 'she' => true, 'it' => true,
        'they' => true, 'this' => true, 'that' => true, 'do' => true, 'does' => true, 'did' => true,
        'can' => true, 'will' => true, 'her' => true, 'his' => true, 'him' => true, 'me' => true,
        'us' => true, 'them' => true, 'our' => true, 'their' => true, 'its' => true, 'with' => true,
        'for' => true, 'from' => true, 'by' => true, 'as' => true, 'into' => true, 'when' => true,
        'what' => true, 'how' => true, 'why' => true, 'which' => true, 'who' => true, 'not' => true,
        'but' => true, 'so' => true, 'if' => true, 'than' => true, 'then' => true, 'also' => true,
        'very' => true, 'just' => true, 'about' => true, 'like' => true, 'have' => true, 'has' => true,
        'had' => true, 'been' => true, 'being' => true, 'there' => true, 'here' => true, 'all' => true,
        'some' => true, 'any' => true, 'more' => true, 'most' => true, 'other' => true, 'such' => true,
        'no' => true, 'nor' => true, 'too' => true, 'only' => true, 'own' => true, 'same' => true,
        'each' => true, 'few' => true, 'many' => true, 'much' => true, 'these' => true, 'those' => true,
    ];

    public function __construct(private readonly Clock $clock) {}

    public function subjectSummary(?ChildProfile $child = null): array
    {
        $age = $child !== null ? $this->childAge($child) : null;
        $published = $age === null
            ? $this->publishedTopics()
            : $this->ageFilteredTopics($age);
        $byDomain = [];
        foreach ($published as $topic) {
            $slug = $this->domainSlug((string) $topic['domain']);
            if (! isset($byDomain[$slug])) {
                $byDomain[$slug] = [
                    'key' => $slug,
                    'domain' => (string) $topic['domain'],
                    'title' => BiMsg::of(
                        $this->domainTitleZh((string) $topic['domain']),
                        (string) $topic['domain'],
                    ),
                    'emoji' => $this->emojiForDomain((string) $topic['domain']),
                    'topic_count' => 0,
                ];
            }
            $byDomain[$slug]['topic_count']++;
        }

        uasort($byDomain, fn ($a, $b) => strcmp($a['domain'], $b['domain']));

        return [
            'subject' => 'Science',
            'title' => BiMsg::of('科学', 'Science'),
            'emoji' => '🔭',
            'topic_count' => count($published),
            'attribution' => BiMsg::of(
                '内容改编自 Marble Skill Taxonomy（科学主题）· ODbL / CC BY-SA 4.0',
                'Adapted from Marble Skill Taxonomy (Science) · ODbL / CC BY-SA 4.0',
            ),
            'domains' => array_values($byDomain),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topicsForDomain(string $domainKey, array $readIds = [], ?ChildProfile $child = null): array
    {
        $slug = $this->normalizeDomainKey($domainKey);
        $age = $child !== null ? $this->childAge($child) : null;
        $out = [];
        foreach ($this->publishedTopics() as $topic) {
            if ($this->domainSlug((string) $topic['domain']) !== $slug) {
                continue;
            }
            if ($age !== null && ! $this->fitsAge($topic, $age)) {
                continue;
            }
            $out[] = $this->listItem($topic, in_array($topic['id'], $readIds, true), $readIds, $age);
        }

        usort($out, fn ($a, $b) => [$a['locked'] ? 1 : 0, $a['age_start'], $a['name_zh']] <=> [$b['locked'] ? 1 : 0, $b['age_start'], $b['name_zh']]);

        return $out;
    }

    public function topicDetail(string $topicId, array $readIds = [], ?ChildProfile $child = null): ?array
    {
        $topic = $this->publishedTopics()[$topicId] ?? null;
        if ($topic === null) {
            return null;
        }

        $age = $child !== null ? $this->childAge($child) : null;
        if ($age !== null && ! $this->fitsAge($topic, $age)) {
            return null;
        }

        $overlay = $this->overlay()[$topicId] ?? null;
        $bodyEn = $this->shorten((string) $topic['description'], 420);
        $bodyZh = is_array($overlay) ? (string) ($overlay['body'] ?? '') : '';
        if ($bodyZh === '') {
            $bodyZh = $bodyEn;
        }
        $nameZh = (string) ($overlay['name'] ?? $topic['name']);
        $nameEn = (string) $topic['name'];
        $locked = $this->isLocked($topicId, $readIds, $age);
        $unlockHint = $locked ? $this->unlockHint($topicId, $readIds, $age) : null;
        $words = $this->wordsForTopic($topic, $overlay, $bodyEn);
        $video = $this->normalizeVideo(is_array($overlay) ? ($overlay['video'] ?? null) : null);

        return [
            'id' => $topic['id'],
            'domain_key' => $this->domainSlug((string) $topic['domain']),
            'domain' => BiMsg::of(
                $this->domainTitleZh((string) $topic['domain']),
                (string) $topic['domain'],
            ),
            'name' => BiMsg::of($nameZh, $nameEn),
            'name_zh' => $nameZh,
            'name_en' => $nameEn,
            'body' => BiMsg::of($bodyZh, $bodyEn),
            'body_zh' => $bodyZh,
            'body_en' => $bodyEn,
            'emoji' => $this->emojiForTopic($topic, $overlay),
            'age_start' => (int) $topic['ageRangeStart'],
            'age_end' => (int) $topic['ageRangeEnd'],
            'read' => in_array($topicId, $readIds, true),
            'locked' => $locked,
            'unlock_hint' => $unlockHint,
            'words' => $words,
            'video' => $video,
            'prerequisites' => $this->related($topicId, 'prerequisites', $readIds, $age),
            'next_topics' => $this->related($topicId, 'next', $readIds, $age),
            'attribution' => BiMsg::of(
                'Marble Skill Taxonomy · https://withmarble.com · ODbL 1.0 / CC BY-SA 4.0',
                'Marble Skill Taxonomy · https://withmarble.com · ODbL 1.0 / CC BY-SA 4.0',
            ),
        ];
    }

    public function isPublished(string $topicId): bool
    {
        return isset($this->publishedTopics()[$topicId]);
    }

    public function isVisibleToChild(string $topicId, ChildProfile $child): bool
    {
        $topic = $this->publishedTopics()[$topicId] ?? null;
        if ($topic === null) {
            return false;
        }

        return $this->fitsAge($topic, $this->childAge($child));
    }

    public function isLockedForChild(string $topicId, ChildProfile $child, array $readIds): bool
    {
        if (! $this->isVisibleToChild($topicId, $child)) {
            return true;
        }

        return $this->isLocked($topicId, $readIds, $this->childAge($child));
    }

    /**
     * Whether $word appears in this topic's English body (or curated word list).
     */
    public function topicContainsWord(string $topicId, string $word): bool
    {
        $detail = $this->topicDetail($topicId);
        if ($detail === null) {
            return false;
        }
        $normalized = $this->normalizeWord($word);
        if ($normalized === '') {
            return false;
        }
        foreach ($detail['words'] as $row) {
            if (($row['word'] ?? '') === $normalized) {
                return true;
            }
        }
        $blob = strtolower(str_replace('’', "'", (string) $detail['body_en'].' '.(string) $detail['name_en']));

        return preg_match('/(?<![a-z])'.preg_quote($normalized, '/').'(?![a-z])/', $blob) === 1;
    }

    public function meaningForWord(string $topicId, string $word): ?string
    {
        $detail = $this->topicDetail($topicId);
        if ($detail === null) {
            return null;
        }
        $normalized = $this->normalizeWord($word);
        foreach ($detail['words'] as $row) {
            if (($row['word'] ?? '') === $normalized && trim((string) ($row['meaning'] ?? '')) !== '') {
                return (string) $row['meaning'];
            }
        }

        return null;
    }

    public function domainSlug(string $domain): string
    {
        $slug = strtolower($domain);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function publishedTopics(): array
    {
        $overlay = $this->overlay();
        $published = [];
        foreach ($this->topicsById() as $id => $topic) {
            if (! isset($overlay[$id])) {
                continue;
            }
            $entry = $overlay[$id];
            if (trim((string) ($entry['name'] ?? '')) === ''
                || trim((string) ($entry['body'] ?? '')) === '') {
                continue;
            }
            $published[$id] = $topic;
        }

        return $published;
    }

    /**
     * @return list<string>
     */
    public function candidateTopicIds(int $maxAgeStart = 8, int $perDomain = 8): array
    {
        $byDomain = [];
        foreach ($this->topicsById() as $topic) {
            if ((int) $topic['ageRangeStart'] > $maxAgeStart) {
                continue;
            }
            $byDomain[(string) $topic['domain']][] = $topic;
        }

        $ids = [];
        ksort($byDomain);
        foreach ($byDomain as $items) {
            usort($items, function (array $a, array $b): int {
                $ca = -((float) ($a['centrality'] ?? 0));
                $cb = -((float) ($b['centrality'] ?? 0));

                return [$ca, $a['ageRangeStart'], $a['id']] <=> [$cb, $b['ageRangeStart'], $b['id']];
            });
            foreach (array_slice($items, 0, $perDomain) as $topic) {
                $ids[] = (string) $topic['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function topicsById(): array
    {
        if ($this->topicsById !== null) {
            return $this->topicsById;
        }

        $path = self::DATA_DIR.'/topics.science.json';
        $payload = $this->readJson($path);
        $map = [];
        foreach ($payload['topics'] ?? [] as $topic) {
            if (! is_array($topic) || empty($topic['id'])) {
                continue;
            }
            $map[(string) $topic['id']] = $topic;
        }
        $this->topicsById = $map;

        return $this->topicsById;
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     body: string,
     *     emoji?: string|null,
     *     reviewed: bool,
     *     words?: list<array{word: string, meaning: string}>,
     *     video?: array{provider: string, id: string}|null,
     *     video_query?: string|null
     * }>
     */
    public function overlay(): array
    {
        if ($this->overlay !== null) {
            return $this->overlay;
        }

        $path = self::DATA_DIR.'/zh-overlay.json';
        $payload = is_file($path) ? $this->readJson($path) : ['entries' => []];
        $entries = $payload['entries'] ?? [];
        $map = [];
        foreach ($entries as $id => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $map[(string) $id] = [
                'name' => (string) ($entry['name'] ?? ''),
                'body' => (string) ($entry['body'] ?? ''),
                'emoji' => isset($entry['emoji']) ? (string) $entry['emoji'] : null,
                'reviewed' => ($entry['reviewed'] ?? false) === true,
                'words' => $this->parseOverlayWords($entry['words'] ?? null),
                'video' => $this->normalizeVideo($entry['video'] ?? null),
                'video_query' => isset($entry['video_query']) ? trim((string) $entry['video_query']) : null,
            ];
        }
        $this->overlay = $map;

        return $this->overlay;
    }

    public function writeOverlay(array $entries): void
    {
        $path = self::DATA_DIR.'/zh-overlay.json';
        $payload = [
            'version' => 1,
            'source' => 'Growth Planet Chinese kid overlays (adapted from Marble Skill Taxonomy Science topics)',
            'generated_at' => gmdate('c'),
            'entries' => $entries,
        ];
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
        $this->overlay = null;
    }

    private function childAge(ChildProfile $child): int
    {
        $child->loadMissing('family');

        return $this->clock->ageToday((string) $child->birth_date, (string) $child->family->timezone);
    }

    /**
     * @param  array<string, mixed>  $topic
     */
    private function fitsAge(array $topic, int $age): bool
    {
        return $age >= (int) $topic['ageRangeStart'] && $age <= (int) $topic['ageRangeEnd'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function ageFilteredTopics(int $age): array
    {
        $out = [];
        foreach ($this->publishedTopics() as $id => $topic) {
            if ($this->fitsAge($topic, $age)) {
                $out[$id] = $topic;
            }
        }

        return $out;
    }

    private function isLocked(string $topicId, array $readIds, ?int $age): bool
    {
        foreach ($this->blockingPrerequisiteIds($topicId, $age) as $prereqId) {
            if (! in_array($prereqId, $readIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function blockingPrerequisiteIds(string $topicId, ?int $age): array
    {
        $published = $this->publishedTopics();
        $ids = [];
        foreach ($this->dependencies() as $edge) {
            if (($edge['topicId'] ?? '') !== $topicId) {
                continue;
            }
            $prereqId = (string) $edge['prerequisiteId'];
            $prereq = $published[$prereqId] ?? null;
            if ($prereq === null) {
                continue;
            }
            if ($age !== null && ! $this->fitsAge($prereq, $age)) {
                continue;
            }
            $ids[] = $prereqId;
        }

        return array_values(array_unique($ids));
    }

    private function unlockHint(string $topicId, array $readIds, ?int $age): ?array
    {
        foreach ($this->blockingPrerequisiteIds($topicId, $age) as $prereqId) {
            if (in_array($prereqId, $readIds, true)) {
                continue;
            }
            $topic = $this->publishedTopics()[$prereqId] ?? null;
            if ($topic === null) {
                continue;
            }
            $item = $this->listItem($topic, false, $readIds, $age);

            return [
                'id' => $item['id'],
                'name_zh' => $item['name_zh'],
                'name_en' => $item['name_en'],
                'emoji' => $item['emoji'],
            ];
        }

        return null;
    }

    /**
     * @return list<array{topicId: string, prerequisiteId: string, strength?: string, reason?: string}>
     */
    private function dependencies(): array
    {
        if ($this->dependencies !== null) {
            return $this->dependencies;
        }

        $path = self::DATA_DIR.'/dependencies.science.json';
        $payload = $this->readJson($path);
        $this->dependencies = array_values(array_filter(
            $payload['dependencies'] ?? [],
            fn ($d) => is_array($d) && isset($d['topicId'], $d['prerequisiteId']),
        ));

        return $this->dependencies;
    }

    /**
     * @param  array<string, mixed>  $topic
     * @param  array{name?: string, body?: string, emoji?: string|null}|null  $overlay
     * @return array<string, mixed>
     */
    private function listItem(array $topic, bool $read, array $readIds = [], ?int $age = null): array
    {
        $overlay = $this->overlay()[$topic['id']] ?? null;
        $nameZh = (string) ($overlay['name'] ?? $topic['name']);
        $nameEn = (string) $topic['name'];
        $locked = $this->isLocked((string) $topic['id'], $readIds, $age);

        return [
            'id' => $topic['id'],
            'domain_key' => $this->domainSlug((string) $topic['domain']),
            'name' => BiMsg::of($nameZh, $nameEn),
            'name_zh' => $nameZh,
            'name_en' => $nameEn,
            'emoji' => $this->emojiForTopic($topic, $overlay),
            'age_start' => (int) $topic['ageRangeStart'],
            'age_end' => (int) $topic['ageRangeEnd'],
            'read' => $read,
            'locked' => $locked,
            'unlock_hint' => $locked ? $this->unlockHint((string) $topic['id'], $readIds, $age) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function related(string $topicId, string $direction, array $readIds, ?int $age): array
    {
        $published = $this->publishedTopics();
        $ids = [];
        foreach ($this->dependencies() as $edge) {
            if ($direction === 'prerequisites' && $edge['topicId'] === $topicId) {
                $ids[] = (string) $edge['prerequisiteId'];
            }
            if ($direction === 'next' && $edge['prerequisiteId'] === $topicId) {
                $ids[] = (string) $edge['topicId'];
            }
        }

        $out = [];
        foreach (array_unique($ids) as $id) {
            if (! isset($published[$id])) {
                continue;
            }
            if ($age !== null && ! $this->fitsAge($published[$id], $age)) {
                continue;
            }
            $out[] = $this->listItem($published[$id], in_array($id, $readIds, true), $readIds, $age);
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $topic
     * @param  array{words?: list<array{word: string, meaning: string}>|null, emoji?: string|null}|null  $overlay
     * @return list<array{word: string, meaning: string}>
     */
    private function wordsForTopic(array $topic, ?array $overlay, string $bodyEn): array
    {
        $curated = is_array($overlay) ? ($overlay['words'] ?? []) : [];
        if ($curated !== []) {
            return array_slice($curated, 0, 8);
        }

        $tokens = preg_split('/[^A-Za-z]+/', $bodyEn.' '.(string) $topic['name']) ?: [];
        $out = [];
        $seen = [];
        foreach ($tokens as $token) {
            $word = $this->normalizeWord($token);
            if ($word === '' || isset(self::WORD_SKIP[$word]) || isset($seen[$word])) {
                continue;
            }
            if (preg_match('/^[a-z]{3,16}$/', $word) !== 1) {
                continue;
            }
            $seen[$word] = true;
            $out[] = ['word' => $word, 'meaning' => ''];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{word: string, meaning: string}>
     */
    private function parseOverlayWords(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $word = $this->normalizeWord((string) ($row['word'] ?? $row['text'] ?? ''));
            if ($word === '' || preg_match('/^[a-z]{2,24}$/', $word) !== 1) {
                continue;
            }
            $out[] = [
                'word' => $word,
                'meaning' => trim((string) ($row['meaning'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return array{provider: string, id: string}|null
     */
    private function normalizeVideo(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $provider = strtolower(trim((string) ($raw['provider'] ?? '')));
        $id = trim((string) ($raw['id'] ?? ''));
        if ($provider === 'youtube') {
            if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) !== 1) {
                return null;
            }

            return ['provider' => 'youtube', 'id' => $id];
        }
        if ($provider === 'bilibili') {
            if (preg_match('/^BV[A-Za-z0-9]+$/', $id) !== 1) {
                return null;
            }

            return ['provider' => 'bilibili', 'id' => $id];
        }

        return null;
    }

    private function normalizeWord(string $word): string
    {
        $word = strtolower(trim(str_replace('’', "'", $word)));
        $word = preg_replace('/^[^a-z]+|[^a-z]+$/i', '', $word) ?? '';
        $word = preg_replace("/'s$/", '', $word) ?? $word;

        return strtolower($word);
    }

    /**
     * @param  array<string, mixed>  $topic
     * @param  array{emoji?: string|null}|null  $overlay
     */
    private function emojiForTopic(array $topic, ?array $overlay): string
    {
        $custom = trim((string) ($overlay['emoji'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return $this->emojiForDomain((string) $topic['domain']);
    }

    private function emojiForDomain(string $domain): string
    {
        $slug = $this->domainSlug($domain);

        return self::DOMAIN_EMOJI[$slug] ?? '📘';
    }

    private function normalizeDomainKey(string $domainKey): string
    {
        $raw = rawurldecode($domainKey);
        if (str_contains($raw, ' ') || str_contains($raw, '&')) {
            return $this->domainSlug($raw);
        }

        return $this->domainSlug(str_replace('_', '-', $raw));
    }

    private function domainTitleZh(string $domain): string
    {
        return match ($this->domainSlug($domain)) {
            'animals-of-the-world' => '世界动物',
            'dinosaurs-paleontology' => '恐龙与化石',
            'earths-systems' => '地球系统',
            'ecosystems-habitats' => '生态与栖息地',
            'energy' => '能量',
            'forces-motion' => '力与运动',
            'insects-minibeasts' => '昆虫与小虫子',
            'matter-materials' => '物质与材料',
            'ocean-life' => '海洋生物',
            'organisms-life-processes' => '生物与生命',
            'polar-regions' => '极地',
            'rainforests' => '雨林',
            'scientific-inquiry' => '科学探究',
            'space-exploration' => '太空探索',
            'space-systems-earths-history' => '太空与地球历史',
            'the-human-body' => '人体',
            'volcanoes-earthquakes' => '火山与地震',
            'waves-light-sound' => '光与声音',
            'weather-climate' => '天气与气候',
            default => $domain,
        };
    }

    private function shorten(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Missing encyclopedia data file: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid encyclopedia JSON: {$path}");
        }

        return $decoded;
    }
}
