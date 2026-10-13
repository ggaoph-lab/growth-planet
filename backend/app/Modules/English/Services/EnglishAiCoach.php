<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\HomeworkLlmRouter;
use App\Modules\Task\Services\HomeworkLlmTimeoutException;
use Illuminate\Support\Facades\DB;

/**
 * Shared English Lab AI calls. Vocabulary estimates and progress stay on the child.
 */
class EnglishAiCoach
{
    public function __construct(
        private readonly Clock $clock,
        private readonly FamilyAiConfig $ai,
        private readonly HomeworkLlmRouter $llm,
        private readonly EnglishContentGate $gate,
    ) {}

    public function selectedProvider(ChildProfile $child): ?string
    {
        $cfg = $this->ai->resolve((int) $child->family_id);
        $choice = strtolower(trim((string) ($cfg['provider'] ?? 'auto')));

        return in_array($choice, ['gemini', 'qwen', 'openai'], true) ? $choice : null;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, provider: string, model: string}|null
     */
    public function chatJson(ChildProfile $child, array $messages): ?array
    {
        $choice = $this->selectedProvider($child);
        if ($choice === null) {
            return null;
        }

        [$primary] = $this->llm->attemptsFor($choice, (int) $child->family_id);
        if ($primary === null) {
            return null;
        }

        try {
            $result = $primary['client']->chatJson($messages, $primary['config']);
        } catch (HomeworkLlmTimeoutException) {
            return null;
        }

        if ($result === null || trim((string) ($result['content'] ?? '')) === '') {
            return null;
        }

        return [
            'content' => (string) $result['content'],
            'provider' => $primary['client']->name(),
            'model' => (string) ($result['model'] ?? $primary['config']['model'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>|array<string, mixed>  $items
     */
    public function storeRecord(
        ChildProfile $child,
        string $purpose,
        string $date,
        array $snapshot,
        array $items,
        string $status,
        ?string $provider = null,
        ?string $model = null,
    ): object {
        $id = DB::table('english_ai_records')->insertGetId([
            'child_id' => $child->id,
            'provider' => $provider,
            'model' => $model,
            'purpose' => $purpose,
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

    public function findRecord(ChildProfile $child, string $purpose, string $date): ?object
    {
        return DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('purpose', $purpose)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, recent_accuracy: float|null}  $snapshot
     * @return list<array<string, mixed>>
     */
    public function reorderSession(ChildProfile $child, array $candidates, array $snapshot, string $date): array
    {
        if ($candidates === []) {
            return [];
        }

        $existing = $this->findRecord($child, 'session_plan', $date);
        if ($existing !== null) {
            return $this->applyOrder($candidates, json_decode((string) $existing->items, true));
        }

        $keys = array_values(array_map(fn ($item) => (string) $item['key'], $candidates));
        $provider = null;
        $model = null;
        $ordered = null;
        $reply = $this->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'You reorder a young child\'s English practice cards. Reply JSON only: {"order":["key1","key2"]}. Use every key exactly once. Prefer missed_words related cards earlier. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'profile' => $snapshot,
                    'keys' => $keys,
                    'cards' => array_map(fn ($item) => [
                        'key' => $item['key'],
                        'text' => $item['text'] ?? '',
                        'kind' => $item['kind'] ?? '',
                        'review' => (bool) ($item['review'] ?? false),
                        'probe' => (bool) ($item['probe'] ?? false),
                        'challenge' => (bool) ($item['challenge'] ?? false),
                    ], $candidates),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        if ($reply !== null) {
            $provider = $reply['provider'];
            $model = $reply['model'];
            $decoded = $this->decodeJson($reply['content']);
            $ordered = is_array($decoded['order'] ?? null)
                ? $this->gate->reorderKeys($keys, $decoded['order'])
                : null;
        }

        $status = $ordered === null ? 'local' : 'ai';
        $this->storeRecord(
            $child,
            'session_plan',
            $date,
            $snapshot,
            ['order' => $ordered ?? $keys],
            $status,
            $provider,
            $model,
        );

        return $this->applyOrder($candidates, ['order' => $ordered ?? $keys]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>}  $snapshot
     * @return array<string, string>
     */
    public function ensureCoachTips(ChildProfile $child, array $items, array $snapshot, string $date): array
    {
        $record = $this->findRecord($child, 'coach_tips', $date);
        $tips = is_array($decoded = json_decode((string) ($record->items ?? '[]'), true)) ? $decoded : [];
        if (! is_array($tips)) {
            $tips = [];
        }
        $map = [];
        foreach ($tips as $row) {
            if (is_array($row) && isset($row['key'], $row['tip'])) {
                $map[(string) $row['key']] = (string) $row['tip'];
            }
        }

        $missing = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key !== '' && ! isset($map[$key])) {
                $missing[] = $item;
            }
        }

        if ($missing === [] || $record !== null) {
            return $map;
        }

        $provider = null;
        $model = null;
        $fromAi = [];
        $reply = $this->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'You write one short Simplified Chinese coaching tip per card for a young child who missed it. Reply JSON only: {"tips":[{"key":"...","tip":"..."}]} . Tip max 40 Chinese characters. Include a tiny English example when helpful. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'profile' => $snapshot,
                    'items' => array_map(fn ($item) => [
                        'key' => $item['key'],
                        'text' => $item['text'] ?? '',
                        'meaning' => $item['meaning'] ?? ($item['question'] ?? ''),
                    ], $missing),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        if ($reply !== null) {
            $provider = $reply['provider'];
            $model = $reply['model'];
            $decoded = $this->decodeJson($reply['content']);
            foreach ($decoded['tips'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = (string) ($row['key'] ?? '');
                $tip = trim((string) ($row['tip'] ?? ''));
                if ($key === '' || $tip === '' || mb_strlen($tip) > 80) {
                    continue;
                }
                $fromAi[] = ['key' => $key, 'tip' => $tip];
                $map[$key] = $tip;
            }
        }

        foreach ($missing as $item) {
            $key = (string) $item['key'];
            if (! isset($map[$key])) {
                $text = (string) ($item['text'] ?? '');
                $meaning = (string) ($item['meaning'] ?? $item['question'] ?? '');
                $map[$key] = $this->fallbackTip($text, $meaning);
                $fromAi[] = ['key' => $key, 'tip' => $map[$key]];
            }
        }

        $status = $provider === null ? 'local' : (count($fromAi) === count($missing) && $reply !== null ? 'ai' : 'mixed');
        $this->storeRecord($child, 'coach_tips', $date, $snapshot, array_values(array_map(
            fn ($key, $tip) => ['key' => $key, 'tip' => $tip],
            array_keys($map),
            array_values($map),
        )), $status, $provider, $model);

        return $map;
    }

    public function tipFor(ChildProfile $child, string $key, string $date, string $text, string $meaning): string
    {
        $record = $this->findRecord($child, 'coach_tips', $date);
        $tips = is_array($decoded = json_decode((string) ($record->items ?? '[]'), true)) ? $decoded : [];
        foreach ($tips as $row) {
            if (is_array($row) && (string) ($row['key'] ?? '') === $key) {
                $tip = trim((string) ($row['tip'] ?? ''));
                if ($tip !== '') {
                    return $tip;
                }
            }
        }

        $fallback = $this->fallbackTip($text, $meaning);
        $reply = $this->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'Write one short Simplified Chinese coaching tip for a young child. Reply JSON only: {"tip":"..."}. Max 40 Chinese characters. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode(['text' => $text, 'meaning' => $meaning], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $tip = $fallback;
        $provider = null;
        $model = null;
        $status = 'local';
        if ($reply !== null) {
            $decoded = $this->decodeJson($reply['content']);
            $candidate = trim((string) ($decoded['tip'] ?? ''));
            if ($candidate !== '' && mb_strlen($candidate) <= 80) {
                $tip = $candidate;
                $provider = $reply['provider'];
                $model = $reply['model'];
                $status = 'ai';
            }
        }

        $items = is_array($tips) ? $tips : [];
        $items[] = ['key' => $key, 'tip' => $tip];
        if ($record === null) {
            $this->storeRecord($child, 'coach_tips', $date, ['on_demand' => true], $items, $status, $provider, $model);
        } else {
            DB::table('english_ai_records')->where('id', $record->id)->update([
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'status' => $status === 'ai' && $record->status === 'local' ? 'mixed' : ($record->status === 'ai' || $status === 'ai' ? ($record->status === 'local' ? 'mixed' : $record->status) : $record->status),
                'provider' => $record->provider ?? $provider,
                'model' => $record->model ?? $model,
            ]);
        }

        return $tip;
    }

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>}  $snapshot
     * @return array{payload: array<string, mixed>, record: object, status: string}
     */
    public function generateChallengeDraft(ChildProfile $child, string $kind, array $snapshot): array
    {
        $kind = $kind === 'story' ? 'story' : 'grammar';
        $purpose = $kind.'_draft';
        $date = $this->clock->today($child->family->timezone)->toDateString();
        $provider = null;
        $model = null;
        $payload = null;

        $reply = $this->chatJson($child, [
            [
                'role' => 'system',
                'content' => $kind === 'story'
                    ? 'Write one tiny English story check for a young child. Reply JSON only: {"prompt":"story <=40 words","question":"Chinese question","options":["a","b","c"],"answer":"one option","tip":"short Chinese tip","emoji":"📖"}. No personal data.'
                    : 'Write one tiny English grammar check for a young child. Reply JSON only: {"prompt":"English sentence with a blank like I ___ happy.","text":"same or short English","question":"Chinese instruction","options":["am","is","are"],"answer":"one option","tip":"short Chinese tip","emoji":"🧩"}. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        if ($reply !== null) {
            $provider = $reply['provider'];
            $model = $reply['model'];
            $decoded = $this->decodeJson($reply['content']);
            $payload = $this->gate->challengeDraft($kind, $decoded, (int) ($snapshot['band'] ?? 1));
        }

        if ($payload === null) {
            $payload = $this->localChallenge($kind, (int) ($snapshot['band'] ?? 1));
            $status = 'local';
        } else {
            $status = 'ai';
        }

        $existing = $this->findRecord($child, $purpose, $date);
        if ($existing !== null) {
            DB::table('english_ai_records')->where('id', $existing->id)->update([
                'provider' => $provider,
                'model' => $model,
                'profile_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'items' => json_encode([$payload], JSON_UNESCAPED_UNICODE),
                'status' => $status,
            ]);
            $record = DB::table('english_ai_records')->where('id', $existing->id)->first();
        } else {
            $record = $this->storeRecord($child, $purpose, $date, $snapshot, [$payload], $status, $provider, $model);
        }
        if ($record === null) {
            throw new \RuntimeException('english_ai_record_missing');
        }

        return ['payload' => $payload, 'record' => $record, 'status' => $status];
    }

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, accuracy_7d: float|null, missed_top: list<string>, themes: list<string>}  $stats
     * @return array{summary: string, focuses: list<string>, status: string}
     */
    public function parentWeeklySummary(ChildProfile $child, array $stats, string $weekStart): array
    {
        $existing = $this->findRecord($child, 'parent_summary', $weekStart);
        if ($existing !== null) {
            $items = json_decode((string) $existing->items, true);
            if (is_array($items)) {
                return [
                    'summary' => (string) ($items['summary'] ?? ''),
                    'focuses' => array_values(array_filter(array_map('strval', $items['focuses'] ?? []))),
                    'status' => (string) $existing->status,
                ];
            }
        }

        $localSummary = $this->localParentSummary($stats);
        $provider = null;
        $model = null;
        $summary = $localSummary['summary'];
        $focuses = $localSummary['focuses'];
        $status = 'local';

        $reply = $this->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'Write a brief parent note in Simplified Chinese about a child\'s English Lab week. Reply JSON only: {"summary":"...","focuses":["..."]}. No names or personal data. Keep summary under 80 Chinese characters.',
            ],
            [
                'role' => 'user',
                'content' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        if ($reply !== null) {
            $decoded = $this->decodeJson($reply['content']);
            $candidate = trim((string) ($decoded['summary'] ?? ''));
            $candidateFocus = [];
            foreach ($decoded['focuses'] ?? [] as $focus) {
                $focus = trim((string) $focus);
                if ($focus !== '' && mb_strlen($focus) <= 40) {
                    $candidateFocus[] = $focus;
                }
            }
            if ($candidate !== '' && mb_strlen($candidate) <= 160) {
                $summary = $candidate;
                $focuses = $candidateFocus !== [] ? array_slice($candidateFocus, 0, 5) : $focuses;
                $provider = $reply['provider'];
                $model = $reply['model'];
                $status = 'ai';
            }
        }

        $this->storeRecord($child, 'parent_summary', $weekStart, $stats, [
            'summary' => $summary,
            'focuses' => $focuses,
        ], $status, $provider, $model);

        return ['summary' => $summary, 'focuses' => $focuses, 'status' => $status];
    }

    public function fallbackTip(string $text, string $meaning): string
    {
        $text = trim($text);
        $meaning = trim($meaning);
        if ($text !== '' && $meaning !== '') {
            return "再听一遍：{$text} 意思是「{$meaning}」。";
        }

        return '再听一遍，记住正确说法。';
    }

    public function encouragement(): string
    {
        $lines = [
            '真棒，记住这个表达！',
            '不错，继续保持。',
            '答对了，明天还能见到它。',
        ];

        return $lines[array_rand($lines)];
    }

    /**
     * @return array<string, mixed>
     */
    private function localChallenge(string $kind, int $band): array
    {
        if ($kind === 'story') {
            $raw = [
                'prompt' => 'A cat sits on a red bag. The cat is happy.',
                'question' => '小猫怎么样？',
                'options' => ['很开心', '很饿', '在跑'],
                'answer' => '很开心',
                'tip' => 'happy 表示开心。',
                'emoji' => '📖',
            ];
        } else {
            $raw = [
                'prompt' => 'I ___ happy.',
                'text' => 'I ___ happy.',
                'question' => '选正确的词',
                'options' => ['am', 'is', 'are'],
                'answer' => 'am',
                'tip' => 'I 后面用 am。',
                'emoji' => '🧩',
            ];
        }

        $payload = $this->gate->challengeDraft($kind, $raw, $band);
        if ($payload === null) {
            throw new \RuntimeException('local_challenge_invalid');
        }

        return $payload;
    }

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, accuracy_7d: float|null, missed_top: list<string>, themes: list<string>}  $stats
     * @return array{summary: string, focuses: list<string>}
     */
    private function localParentSummary(array $stats): array
    {
        $accuracy = $stats['accuracy_7d'];
        $missed = array_slice($stats['missed_top'] ?? [], 0, 3);
        $themes = array_slice($stats['themes'] ?? [], 0, 2);
        $accuracyText = $accuracy === null ? '本周练习还不多' : '近 7 天正确率约 '.round($accuracy * 100).'%';
        $missText = $missed === [] ? '暂无突出易错词' : '易错：'.implode('、', $missed);
        $themeText = $themes === [] ? '' : '；可多练：'.implode('、', $themes);

        return [
            'summary' => $accuracyText.'。'.$missText.$themeText.'。',
            'focuses' => array_values(array_unique([...$missed, ...$themes])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function applyOrder(array $candidates, mixed $items): array
    {
        $order = is_array($items) ? ($items['order'] ?? $items) : [];
        if (! is_array($order)) {
            return $candidates;
        }
        $byKey = [];
        foreach ($candidates as $item) {
            $byKey[(string) $item['key']] = $item;
        }
        $ordered = [];
        foreach ($order as $key) {
            $key = (string) $key;
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }
        foreach ($byKey as $item) {
            $ordered[] = $item;
        }

        return $ordered;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
