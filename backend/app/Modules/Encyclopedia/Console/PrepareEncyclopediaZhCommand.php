<?php

namespace App\Modules\Encyclopedia\Console;

use App\Modules\Encyclopedia\Services\EncyclopediaCatalog;
use App\Modules\Task\Services\HomeworkLlmRouter;
use Illuminate\Console\Command;

/**
 * Generate / refresh Chinese kid overlays for Science encyclopedia topics via family AI.
 */
class PrepareEncyclopediaZhCommand extends Command
{
    protected $signature = 'encyclopedia:prepare-zh
        {--family=1 : Family id whose AI keys/provider to use}
        {--limit=0 : Max topics to generate (0 = all candidates)}
        {--batch=5 : Topics per AI request}
        {--force : Regenerate topics that already have overlays}';

    protected $description = 'Generate Chinese kid encyclopedia overlays from Marble Science topics using configured AI';

    public function handle(EncyclopediaCatalog $catalog, HomeworkLlmRouter $llm): int
    {
        $familyId = (int) $this->option('family');
        $limit = max(0, (int) $this->option('limit'));
        $batchSize = max(1, min(10, (int) $this->option('batch')));
        $force = (bool) $this->option('force');

        if (! $llm->isAiConfigured($familyId)) {
            $this->error("No AI provider configured for family {$familyId}.");

            return self::FAILURE;
        }

        $existing = $catalog->overlay();
        $candidates = $catalog->candidateTopicIds();
        if ($limit > 0) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $todo = [];
        foreach ($candidates as $id) {
            if (! $force && isset($existing[$id]) && trim((string) ($existing[$id]['name'] ?? '')) !== '' && trim((string) ($existing[$id]['body'] ?? '')) !== '') {
                continue;
            }
            $todo[] = $id;
        }

        if ($todo === []) {
            $this->info('All candidate topics already have overlays. Use --force to regenerate.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Generating overlays for %d topics (family %d)…', count($todo), $familyId));
        $topicsById = $catalog->topicsById();
        $merged = $existing;

        foreach (array_chunk($todo, $batchSize) as $chunkIndex => $chunk) {
            $payload = [];
            foreach ($chunk as $id) {
                $topic = $topicsById[$id] ?? null;
                if ($topic === null) {
                    continue;
                }
                $payload[] = [
                    'id' => $id,
                    'domain' => $topic['domain'],
                    'name' => $topic['name'],
                    'description' => $topic['description'],
                    'ageRangeStart' => $topic['ageRangeStart'],
                    'ageRangeEnd' => $topic['ageRangeEnd'],
                ];
            }

            $this->line('Batch '.($chunkIndex + 1).': '.implode(', ', array_column($payload, 'id')));
            $entries = $this->generateBatch($llm, $familyId, $payload);
            if ($entries === null) {
                $this->error('AI batch failed; writing progress so far.');
                $catalog->writeOverlay($this->normalizeEntries($merged));

                return self::FAILURE;
            }

            foreach ($entries as $id => $entry) {
                $merged[$id] = $entry;
            }
            $catalog->writeOverlay($this->normalizeEntries($merged));
            $this->info('Saved '.count($entries).' overlays (total '.count($merged).').');
            usleep(400_000);
        }

        $this->info('Done. Published topics: '.count($catalog->publishedTopics()));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     * @return array<string, array{name: string, body: string, emoji: string}>|null
     */
    private function generateBatch(HomeworkLlmRouter $llm, int $familyId, array $topics): ?array
    {
        [$primary] = $llm->attemptsFor(null, $familyId);
        if ($primary === null) {
            return null;
        }

        $config = $primary['config'];
        $config['timeout'] = max(45, (int) ($config['timeout'] ?? 30));

        $messages = [
            [
                'role' => 'system',
                'content' => <<<'PROMPT'
You write Chinese pictorial-encyclopedia cards for grade-1 children (about ages 5–8).
Return ONLY a JSON object:
{"entries":[{"id":"mt_...","name":"中文标题","body":"2到4句儿童中文短文","emoji":"一个emoji"}]}
Rules:
- Keep science accurate but warm and concrete; no scary medical detail.
- Do not invent facts that contradict the English source.
- Chinese names should be short (≤16 chars preferred).
- body: simplified Chinese, spoken style a parent can read aloud.
- Exactly one entry per input id; preserve ids.
PROMPT,
            ],
            [
                'role' => 'user',
                'content' => json_encode(['topics' => $topics], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $result = $primary['client']->chatJson($messages, $config);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }

        if ($result === null || trim((string) ($result['content'] ?? '')) === '') {
            $this->error('Empty AI response from '.$primary['client']->name());

            return null;
        }

        $decoded = json_decode((string) $result['content'], true);
        if (! is_array($decoded)) {
            $this->error('AI returned non-JSON');

            return null;
        }

        $rows = $decoded['entries'] ?? null;
        if (! is_array($rows)) {
            // Allow a map form { "mt_x": {name, body} }
            $rows = [];
            foreach ($decoded as $id => $entry) {
                if (is_string($id) && str_starts_with($id, 'mt_') && is_array($entry)) {
                    $rows[] = array_merge(['id' => $id], $entry);
                }
            }
        }

        $out = [];
        $wanted = array_column($topics, 'id');
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if (! in_array($id, $wanted, true)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            $emoji = trim((string) ($row['emoji'] ?? ''));
            if ($name === '' || $body === '') {
                continue;
            }
            $out[$id] = [
                'name' => $name,
                'body' => $body,
                'emoji' => $emoji !== '' ? $emoji : '📘',
                'reviewed' => false,
            ];
        }

        if (count($out) < count($wanted)) {
            $missing = array_values(array_diff($wanted, array_keys($out)));
            $this->warn('Missing overlays for: '.implode(', ', $missing));
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, array{name?: string, body?: string, emoji?: string|null, reviewed?: bool}>  $merged
     * @return array<string, array{name: string, body: string, emoji: string, reviewed: bool}>
     */
    private function normalizeEntries(array $merged): array
    {
        $out = [];
        ksort($merged);
        foreach ($merged as $id => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $body = trim((string) ($entry['body'] ?? ''));
            if ($name === '' || $body === '') {
                continue;
            }
            $out[(string) $id] = [
                'name' => $name,
                'body' => $body,
                'emoji' => trim((string) ($entry['emoji'] ?? '')) ?: '📘',
                'reviewed' => ($entry['reviewed'] ?? false) === true,
            ];
        }

        return $out;
    }
}
