<?php

namespace App\Modules\Encyclopedia\Services;

use App\Modules\Core\Support\BiMsg;
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

    /** @var array<string, array{name: string, body: string, emoji?: string|null, reviewed: bool}>|null */
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

    public function subjectSummary(): array
    {
        $published = $this->publishedTopics();
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
    public function topicsForDomain(string $domainKey, array $readIds = []): array
    {
        $slug = $this->normalizeDomainKey($domainKey);
        $out = [];
        foreach ($this->publishedTopics() as $topic) {
            if ($this->domainSlug((string) $topic['domain']) !== $slug) {
                continue;
            }
            $out[] = $this->listItem($topic, in_array($topic['id'], $readIds, true));
        }

        usort($out, fn ($a, $b) => [$a['age_start'], $a['name']] <=> [$b['age_start'], $b['name']]);

        return $out;
    }

    public function topicDetail(string $topicId, array $readIds = []): ?array
    {
        $topic = $this->publishedTopics()[$topicId] ?? null;
        if ($topic === null) {
            return null;
        }

        $overlay = $this->overlay()[$topicId] ?? null;
        $bodyEn = $this->shorten((string) $topic['description'], 420);
        $bodyZh = is_array($overlay) ? (string) ($overlay['body'] ?? '') : '';
        if ($bodyZh === '') {
            $bodyZh = $bodyEn;
        }

        return [
            'id' => $topic['id'],
            'domain_key' => $this->domainSlug((string) $topic['domain']),
            'domain' => BiMsg::of(
                $this->domainTitleZh((string) $topic['domain']),
                (string) $topic['domain'],
            ),
            'name' => BiMsg::of(
                (string) ($overlay['name'] ?? $topic['name']),
                (string) $topic['name'],
            ),
            'body' => BiMsg::of($bodyZh, $bodyEn),
            'emoji' => $this->emojiForTopic($topic, $overlay),
            'age_start' => (int) $topic['ageRangeStart'],
            'age_end' => (int) $topic['ageRangeEnd'],
            'read' => in_array($topicId, $readIds, true),
            'prerequisites' => $this->related($topicId, 'prerequisites', $readIds),
            'next_topics' => $this->related($topicId, 'next', $readIds),
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
            if (($entry['reviewed'] ?? false) !== true
                || trim((string) ($entry['name'] ?? '')) === ''
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
    public function candidateTopicIds(int $maxAgeStart = 8, int $perDomain = 3): array
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
     * @return array<string, array{name: string, body: string, emoji?: string|null, reviewed: bool}>
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
    private function listItem(array $topic, bool $read): array
    {
        $overlay = $this->overlay()[$topic['id']] ?? null;

        return [
            'id' => $topic['id'],
            'domain_key' => $this->domainSlug((string) $topic['domain']),
            'name' => BiMsg::of(
                (string) ($overlay['name'] ?? $topic['name']),
                (string) $topic['name'],
            ),
            'emoji' => $this->emojiForTopic($topic, $overlay),
            'age_start' => (int) $topic['ageRangeStart'],
            'age_end' => (int) $topic['ageRangeEnd'],
            'read' => $read,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function related(string $topicId, string $direction, array $readIds): array
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
            $out[] = $this->listItem($published[$id], in_array($id, $readIds, true));
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
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
