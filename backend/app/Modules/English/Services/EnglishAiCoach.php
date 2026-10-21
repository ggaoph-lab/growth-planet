<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\HomeworkLlmRouter;
use App\Modules\Task\Services\HomeworkLlmTimeoutException;
use App\Modules\Task\Support\LlmFailureTracker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shared English Lab AI calls. Vocabulary estimates and progress stay on the child.
 */
class EnglishAiCoach
{
    private bool $preparingDay = false;

    /** A longer provider deadline is safe only in the background preparation process. */
    public function preparingDay(callable $callback): mixed
    {
        $previous = $this->preparingDay;
        $this->preparingDay = true;
        try {
            return $callback();
        } finally {
            $this->preparingDay = $previous;
        }
    }

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

    public function selectedModel(ChildProfile $child): ?string
    {
        $provider = $this->selectedProvider($child);
        if ($provider === null) {
            return null;
        }

        $cfg = $this->ai->resolve((int) $child->family_id);

        return match ($provider) {
            'gemini' => trim((string) ($cfg['gemini_model'] ?? '')) ?: null,
            'qwen' => trim((string) ($cfg['qwen_model'] ?? '')) ?: null,
            'openai' => trim((string) ($cfg['openai_model'] ?? '')) ?: null,
            default => null,
        };
    }

    /**
     * Kid UI status: ok (green), failed (gray after a selected engine missed), off (gray, not selected).
     *
     * @return 'ok'|'failed'|'off'
     */
    public function connectionStatus(ChildProfile $child, string $date): string
    {
        if ($this->selectedProvider($child) === null) {
            return 'off';
        }

        $statuses = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->pluck('status');

        if ($statuses->isEmpty()) {
            return 'failed';
        }
        if ($statuses->contains(fn ($status) => in_array((string) $status, ['ai', 'mixed'], true))) {
            return 'ok';
        }

        return 'failed';
    }

    /**
     * Sanitized failure code for the kid UI when ai_status is failed (null when ok/off).
     */
    public function failureHint(ChildProfile $child, string $date): ?string
    {
        if ($this->connectionStatus($child, $date) !== 'failed') {
            return null;
        }

        $rows = DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('status', 'local')
            ->orderByDesc('id')
            ->get(['profile_snapshot']);

        foreach ($rows as $row) {
            $snap = json_decode((string) ($row->profile_snapshot ?? ''), true);
            $error = is_array($snap) ? trim((string) ($snap['error'] ?? '')) : '';
            if ($error !== '') {
                return $error;
            }
        }

        return 'unavailable';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, provider: string, model: string}|null
     */
    public function chatJson(ChildProfile $child, array $messages): ?array
    {
        LlmFailureTracker::clear();

        $choice = $this->selectedProvider($child);
        if ($choice === null) {
            LlmFailureTracker::set('no_key');

            return null;
        }

        [$primary] = $this->llm->attemptsFor($choice, (int) $child->family_id);
        if ($primary === null) {
            LlmFailureTracker::set('no_key');

            return null;
        }

        // Kid plan/answer paths must fail soft quickly; homework can keep the longer default.
        $config = $primary['config'];
        $config['timeout'] = $this->preparingDay ? 45 : max(5, min(
            (int) ($config['timeout'] ?? config('services.'.$choice.'.timeout', 30)),
            (int) config('services.english_lab.timeout', 12),
        ));

        try {
            $result = $primary['client']->chatJson($messages, $config);
        } catch (HomeworkLlmTimeoutException) {
            LlmFailureTracker::set('timeout');

            return null;
        }

        if ($result === null || trim((string) ($result['content'] ?? '')) === '') {
            if (LlmFailureTracker::peek() === null) {
                LlmFailureTracker::set('empty');
            }

            return null;
        }

        LlmFailureTracker::clear();

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
        if ($status === 'local') {
            $error = LlmFailureTracker::pull();
            if ($error !== null) {
                $snapshot['error'] = $error;
            }
            if ($model === null || trim((string) $model) === '') {
                $model = $this->selectedModel($child);
            }
        } else {
            LlmFailureTracker::clear();
        }

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
     * Drop a same-day local fallback so a later successful engine call can replace it.
     */
    public function discardLocalRecord(ChildProfile $child, string $purpose, string $date): bool
    {
        return DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('purpose', $purpose)
            ->where('status', 'local')
            ->delete() > 0;
    }

    /**
     * Drop same-day AI/local records so a path or level change can rebuild content.
     *
     * @param  list<string>  $purposes
     */
    public function discardDayRecords(ChildProfile $child, string $date, array $purposes): int
    {
        if ($purposes === []) {
            return 0;
        }

        return DB::table('english_ai_records')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->whereIn('purpose', $purposes)
            ->delete();
    }

    /**
     * High-vocabulary path: 1 grammar + 1 story check in the daily queue (no parent publish needed).
     *
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, path?: string}  $snapshot
     * @return list<array<string, mixed>>
     */
    public function richDailyChallenges(ChildProfile $child, int $slots, array $snapshot, string $date): array
    {
        if ($slots < 1) {
            return [];
        }

        $answered = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where(function ($query) {
                $query->where('item_key', 'like', 'grammar:rich-%')
                    ->orWhere('item_key', 'like', 'story:rich-%');
            })
            ->pluck('item_key')
            ->all();

        $record = $this->findRecord($child, 'rich_challenges', $date);
        if ($record === null) {
            $record = $this->createRichChallenges($child, $snapshot, $date, 1);
        }

        $cards = $this->unansweredRichCards($record, $answered, $slots);
        if (count($cards) < $slots) {
            $round = 2;
            foreach ($answered as $key) {
                if (preg_match('/:rich-(?:\d{4}-\d{2}-\d{2}-)?(\d+)$/', (string) $key, $match) === 1) {
                    $round = max($round, ((int) $match[1]) + 1);
                } elseif (str_contains((string) $key, ':rich-'.$date)) {
                    $round = max($round, 2);
                }
            }
            $record = $this->appendRichChallenges($child, $record, $snapshot, $date, $round);
            $cards = $this->unansweredRichCards($record, $answered, $slots);
        }

        return $cards;
    }

    /**
     * @param  list<string>  $answered
     * @return list<array<string, mixed>>
     */
    private function unansweredRichCards(object $record, array $answered, int $slots): array
    {
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

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, path?: string}  $snapshot
     */
    private function appendRichChallenges(ChildProfile $child, object $record, array $snapshot, string $date, int $round): object
    {
        $existing = json_decode((string) $record->items, true);
        if (! is_array($existing)) {
            $existing = [];
        }
        $extra = $this->buildRichChallengeCards($child, $snapshot, $date, $round);
        $merged = [...$existing, ...$extra];
        DB::table('english_ai_records')->where('id', $record->id)->update([
            'items' => json_encode($merged, JSON_UNESCAPED_UNICODE),
        ]);

        return $this->findRecord($child, 'rich_challenges', $date) ?? $record;
    }

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, path?: string}  $snapshot
     * @return list<array<string, mixed>>
     */
    private function buildRichChallengeCards(ChildProfile $child, array $snapshot, string $date, int $round): array
    {
        $band = max(2, (int) ($snapshot['band'] ?? 2));
        $suffix = $round <= 1 ? $date : $date.'-'.$round;
        $cards = [];
        foreach (['grammar', 'story'] as $kind) {
            $built = $this->generateChallengeDraft($child, $kind, $snapshot + ['band' => $band, 'path' => 'rich']);
            $payload = $built['payload'];
            $cards[] = [
                ...$payload,
                'key' => $kind.':rich-'.$suffix,
                'options' => $payload['options'],
                'text' => (string) ($payload['text'] ?? $payload['prompt']),
                'meaning' => (string) ($payload['question'] ?? $payload['meaning'] ?? ''),
                'correct_answer' => (string) $payload['answer'],
                'coach_tip' => (string) ($payload['tip'] ?? ''),
            ];
        }

        return $cards;
    }

    /**
     * @param  array{band: int, estimated_size: int, known_words: list<string>, missed_words: list<string>, path?: string}  $snapshot
     */
    private function createRichChallenges(ChildProfile $child, array $snapshot, string $date, int $round = 1): object
    {
        $cards = $this->buildRichChallengeCards($child, $snapshot, $date, $round);

        return $this->storeRecord(
            $child,
            'rich_challenges',
            $date,
            $snapshot,
            $cards,
            'local',
            $this->selectedProvider($child),
            null,
        );
    }

    /**
     * Retry a local fallback when the family changes engine/model, or after a cooldown.
     * Avoids hammering a dead key on every page open.
     */
    public function shouldRetryLocal(ChildProfile $child, object $record): bool
    {
        if ((string) ($record->status ?? '') !== 'local') {
            return false;
        }

        $selected = $this->selectedProvider($child);
        if ($selected === null) {
            return false;
        }

        if ($selected !== strtolower(trim((string) ($record->provider ?? '')))) {
            return true;
        }

        $selectedModel = strtolower(trim((string) ($this->selectedModel($child) ?? '')));
        $recordModel = strtolower(trim((string) ($record->model ?? '')));
        if ($selectedModel !== '' && $recordModel !== '' && $selectedModel !== $recordModel) {
            return true;
        }

        $date = (string) ($record->business_date ?? $this->clock->today($child->family->timezone)->toDateString());
        if (! $this->preparingDay && Cache::has(EnglishDailyPreparation::cacheKey($child->id, $date, $selected, $this->selectedModel($child)))) {
            // Scheduled preparation owns cooldown retries; opening a cached day must stay quick.
            return false;
        }

        $created = $record->created_at ?? null;
        if ($created === null) {
            return false;
        }

        $minutes = max(1, (int) config('services.english_lab.retry_after_minutes', 10));
        $createdAt = $created instanceof CarbonImmutable
            ? $created
            : CarbonImmutable::parse((string) $created);

        return $createdAt->lte($this->clock->now()->subMinutes($minutes));
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
            if ($this->shouldRetryLocal($child, $existing)) {
                $this->discardLocalRecord($child, 'session_plan', $date);
            } else {
                return $this->applyOrder($candidates, json_decode((string) $existing->items, true));
            }
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
            $provider ?? $this->selectedProvider($child),
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
        if ($record !== null && $this->shouldRetryLocal($child, $record)) {
            $this->discardLocalRecord($child, 'coach_tips', $date);
            $record = null;
        }

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
        )), $status, $provider ?? $this->selectedProvider($child), $model);

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

        $band = (int) ($snapshot['band'] ?? 1);
        $rich = (($snapshot['path'] ?? '') === 'rich') || $band >= 4;
        if ($kind === 'story') {
            $system = $rich
                ? 'Write one short English story check for a child with a large vocabulary (CEFR A2–B1 / IELTS 4.5–6.0). Weave in 1–2 high-frequency IELTS or Academic Word List words naturally (e.g. discover, journey, challenge, environment). Reply JSON only: {"prompt":"story 25-40 words with a clear detail","question":"Chinese comprehension question","options":["a","b","c"],"answer":"one option","tip":"short Chinese tip","emoji":"📖"}. Avoid baby nouns only. No personal data.'
                : 'Write one tiny English story check for a young child. Reply JSON only: {"prompt":"story <=40 words","question":"Chinese question","options":["a","b","c"],"answer":"one option","tip":"short Chinese tip","emoji":"📖"}. No personal data.';
        } else {
            $system = $rich
                ? 'Write one English grammar check for a child with a large vocabulary (IELTS-oriented). Prefer sentence frames that use common IELTS / AWL vocabulary when natural. Reply JSON only: {"prompt":"English sentence with a blank (tense, preposition, or article)","text":"same","question":"Chinese instruction","options":["opt1","opt2","opt3"],"answer":"one option","tip":"short Chinese tip","emoji":"🧩"}. Target A2–B1. No personal data.'
                : 'Write one tiny English grammar check for a young child. Reply JSON only: {"prompt":"English sentence with a blank like I ___ happy.","text":"same or short English","question":"Chinese instruction","options":["am","is","are"],"answer":"one option","tip":"short Chinese tip","emoji":"🧩"}. No personal data.';
        }
        $reply = $this->chatJson($child, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)],
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
        if ($band >= 4) {
            if ($kind === 'story') {
                $raw = [
                    'prompt' => 'Maya packed her backpack carefully. She almost forgot her map, then remembered it under the chair.',
                    'question' => 'Maya 差点忘了什么？',
                    'options' => ['地图', '椅子', '书包'],
                    'answer' => '地图',
                    'tip' => 'almost forgot 表示差点忘记。',
                    'emoji' => '📖',
                ];
            } else {
                $raw = [
                    'prompt' => 'They have ___ to the library twice this week.',
                    'text' => 'They have ___ to the library twice this week.',
                    'question' => '选正确的词',
                    'options' => ['gone', 'went', 'going'],
                    'answer' => 'gone',
                    'tip' => 'have + 过去分词：have gone。',
                    'emoji' => '🧩',
                ];
            }
        } elseif ($kind === 'story') {
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
