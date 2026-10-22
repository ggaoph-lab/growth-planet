<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Contracts\HomeworkLlmClient;
use Carbon\CarbonImmutable;

/**
 * Turns messy teacher homework text into a structured preview.
 * Uses the selected engine, switches to the other only on connection timeout,
 * then falls back to numbered-list rules.
 */
class HomeworkParser
{
    private const SUBJECTS = ['语文', '数学', '英语', '科学', '道法', '体育', '美术', '音乐', '其他'];

    private const DEFAULT_COINS_PER_ITEM = 3;

    private const DEFAULT_XP_PER_ITEM = 5;

    private const MAX_ITEMS = 20;

    public function __construct(private readonly HomeworkLlmRouter $llm) {}

    public function isAiConfigured(?int $familyId = null): bool
    {
        return $this->llm->isAiConfigured($familyId);
    }

    /**
     * @return array{
     *   parser: string,
     *   subject: string,
     *   task_date: string|null,
     *   title: string,
     *   items: list<array{title: string, detail: string|null, suggested_coins: int, suggested_xp: int}>,
     *   xp_reward: int,
     *   coin_reward: int,
     *   confidence: float,
     *   notes: string|null,
     *   source_text: string,
     *   ai_configured: bool,
     *   providers_available: list<string>
     * }
     */
    public function parse(string $rawText, string $todayDate, ?string $provider = null, ?int $familyId = null): array
    {
        $text = trim(str_replace("\r\n", "\n", $rawText));
        if ($text === '') {
            return $this->emptyResult($text, $todayDate, '原文为空', $familyId);
        }

        [$primary, $fallback] = $this->llm->attemptsFor($provider, $familyId);
        if ($primary === null) {
            $requested = strtolower(trim((string) $provider));
            $choice = $this->llm->chosenProvider($provider, $familyId);
            $note = null;
            if (in_array($choice, ['gemini', 'qwen', 'openai'], true)) {
                $note = in_array($requested, ['gemini', 'qwen', 'openai'], true)
                    ? '所选解析引擎未配置密钥，已使用规则解析，请仔细核对'
                    : '默认解析引擎未配置密钥，已使用规则解析，请仔细核对';
            }

            return $this->parseWithRules($text, $todayDate, $familyId, $note);
        }

        try {
            $ai = $this->parseWithClient($primary['client'], $primary['config'], $text, $todayDate, $familyId);
            if ($ai !== null) {
                return $ai;
            }
        } catch (HomeworkLlmTimeoutException $timeout) {
            if ($fallback !== null) {
                try {
                    $ai = $this->parseWithClient($fallback['client'], $fallback['config'], $text, $todayDate, $familyId);
                    if ($ai !== null) {
                        return $this->annotateTimeoutFallback($ai, $timeout->provider, $fallback['client']->name());
                    }
                } catch (HomeworkLlmTimeoutException) {
                    return $this->parseWithRules($text, $todayDate, $familyId, '连接超时，已使用规则解析，请仔细核对');
                }
            }

            return $this->parseWithRules($text, $todayDate, $familyId, '连接超时，已使用规则解析，请仔细核对');
        }

        $label = $this->providerLabel($primary['client']->name());

        return $this->parseWithRules($text, $todayDate, $familyId, $label.' 解析失败，已使用规则解析，请仔细核对');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function annotateTimeoutFallback(array $result, string $from, string $to): array
    {
        $fromLabel = $this->providerLabel($from);
        $toLabel = $this->providerLabel($to);
        $extra = $fromLabel.' 连接超时，已改用'.$toLabel;
        $notes = trim((string) ($result['notes'] ?? ''));
        $result['notes'] = mb_substr($notes === '' ? $extra : $notes.' '.$extra, 0, 500);

        return $result;
    }

    private function providerLabel(string $name): string
    {
        return match ($name) {
            'qwen' => '千问',
            'openai' => 'ChatGPT',
            default => 'Gemini',
        };
    }

    /**
     * @param  array{api_key: string, model: string}  $config
     * @return array<string, mixed>|null
     */
    private function parseWithClient(HomeworkLlmClient $client, array $config, string $text, string $todayDate, ?int $familyId): ?array
    {
        $subjects = implode('、', self::SUBJECTS);
        $maxItems = self::MAX_ITEMS;
        $system = <<<PROMPT
你是家庭作业结构化助手。把老师发在群里的作业文字，整理成 JSON（不要 markdown）。
字段：
- subject: 学科，只能是：{$subjects}
- task_date: YYYY-MM-DD，原文未写日期则用 {$todayDate}，相对日期（今天/明天/周日）按 {$todayDate} 所在周推断
- title: 简短任务总标题（可含学科）
- items: 数组，每项 {title, detail}；title 是孩子能看懂的短句，detail 是补充说明可空
- confidence: 0~1
- notes: 解析备注，可空
规则：只拆作业条目，不要发明原文没有的内容；忽略寒暄与家长群闲聊；最多 {$maxItems} 条。
PROMPT;

        $reply = $client->chatJson([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $text],
        ], $config);

        if ($reply === null) {
            return null;
        }

        $decoded = $this->decodeJsonObject($reply['content']);
        if ($decoded === null) {
            return null;
        }

        return $this->normalize($decoded, $text, $todayDate, $client->name(), $familyId);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWithRules(string $text, string $todayDate, ?int $familyId = null, ?string $failureNote = null): array
    {
        $lines = preg_split("/\n+/", $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

        $title = $lines[0] ?? '今日作业';
        $subject = $this->guessSubject($title.' '.$text);
        $taskDate = $this->guessDate($text, $todayDate);

        $items = [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && $this->looksLikeTitle($line)) {
                continue;
            }
            if (preg_match('/^\d+[\.、．\)]\s*(.+)$/u', $line, $m)) {
                $items[] = $this->itemFromLine(trim($m[1]));
            }
        }

        if ($items === []) {
            foreach ($lines as $i => $line) {
                if ($i === 0 && $this->looksLikeTitle($line)) {
                    continue;
                }
                $items[] = $this->itemFromLine($line);
            }
        }

        $items = array_slice($items, 0, self::MAX_ITEMS);
        if ($items === []) {
            return $this->emptyResult($text, $todayDate, $failureNote ?? '未能识别出作业条目，请手动编辑', $familyId);
        }

        $available = $this->llm->configuredProviders($familyId);
        $notes = $failureNote ?? ($available === []
            ? '未配置 Gemini / 千问密钥，已使用规则解析，请仔细核对'
            : 'AI 解析失败，已使用规则解析，请仔细核对');

        $payload = [
            'subject' => $subject,
            'task_date' => $taskDate,
            'title' => $this->looksLikeTitle($title) ? $title : ($subject.'作业'),
            'items' => $items,
            'confidence' => count($items) >= 1 ? 0.55 : 0.2,
            'notes' => $notes,
        ];

        return $this->normalize($payload, $text, $todayDate, 'rules', $familyId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload, string $sourceText, string $todayDate, string $parser, ?int $familyId = null): array
    {
        $subject = $this->normalizeSubject($payload['subject'] ?? null);
        $taskDate = $this->normalizeDate($payload['task_date'] ?? null, $todayDate);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = $subject.'作业';
        }
        $title = mb_substr($title, 0, 128);

        $rawItems = $payload['items'] ?? [];
        if (! is_array($rawItems)) {
            $rawItems = [];
        }

        $items = [];
        foreach ($rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $itemTitle = trim((string) ($row['title'] ?? ''));
            if ($itemTitle === '') {
                continue;
            }
            $detail = isset($row['detail']) ? trim((string) $row['detail']) : '';
            $coins = $this->clampInt($row['suggested_coins'] ?? self::DEFAULT_COINS_PER_ITEM, 0, 50, self::DEFAULT_COINS_PER_ITEM);
            $xp = $this->clampInt($row['suggested_xp'] ?? self::DEFAULT_XP_PER_ITEM, 0, 100, self::DEFAULT_XP_PER_ITEM);
            $items[] = [
                'title' => mb_substr($itemTitle, 0, 128),
                'detail' => $detail === '' ? null : mb_substr($detail, 0, 500),
                'suggested_coins' => $coins,
                'suggested_xp' => $xp,
            ];
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        $coinReward = array_sum(array_column($items, 'suggested_coins'));
        $xpReward = array_sum(array_column($items, 'suggested_xp'));
        $confidence = (float) ($payload['confidence'] ?? ($parser === 'rules' ? 0.5 : 0.75));
        $confidence = max(0.0, min(1.0, $confidence));
        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : '';

        return [
            'parser' => $parser,
            'subject' => $subject,
            'task_date' => $taskDate,
            'title' => $title,
            'items' => $items,
            'xp_reward' => min(100, max(0, $xpReward)),
            'coin_reward' => min(50, max(0, $coinReward)),
            'point_reward' => 0,
            'confidence' => round($confidence, 2),
            'notes' => $notes === '' ? null : mb_substr($notes, 0, 500),
            'source_text' => mb_substr($sourceText, 0, 8000),
            'ai_configured' => $this->llm->isAiConfigured($familyId),
            'providers_available' => $this->llm->configuredProviders($familyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $sourceText, string $todayDate, string $notes, ?int $familyId = null): array
    {
        return [
            'parser' => 'rules',
            'subject' => '其他',
            'task_date' => $todayDate,
            'title' => '今日作业',
            'items' => [],
            'xp_reward' => 0,
            'coin_reward' => 0,
            'point_reward' => 0,
            'confidence' => 0.0,
            'notes' => $notes,
            'source_text' => mb_substr($sourceText, 0, 8000),
            'ai_configured' => $this->llm->isAiConfigured($familyId),
            'providers_available' => $this->llm->configuredProviders($familyId),
        ];
    }

    /**
     * @return array{title: string, detail: string|null, suggested_coins: int, suggested_xp: int}
     */
    private function itemFromLine(string $line): array
    {
        $title = $line;
        $detail = null;
        if (preg_match('/^(.+?)[：:]\s*(.+)$/u', $line, $m) && mb_strlen($m[1]) <= 40) {
            $title = trim($m[1]);
            $detail = trim($m[2]);
        }

        return [
            'title' => mb_substr($title, 0, 128),
            'detail' => $detail === null || $detail === '' ? null : mb_substr($detail, 0, 500),
            'suggested_coins' => self::DEFAULT_COINS_PER_ITEM,
            'suggested_xp' => self::DEFAULT_XP_PER_ITEM,
        ];
    }

    private function looksLikeTitle(string $line): bool
    {
        if (preg_match('/^\d+[\.、．\)]/u', $line)) {
            return false;
        }

        return (bool) preg_match('/任务|作业|学习|功课/u', $line)
            || $this->guessSubject($line) !== '其他';
    }

    private function guessSubject(string $text): string
    {
        foreach (self::SUBJECTS as $subject) {
            if ($subject === '其他') {
                continue;
            }
            if (mb_strpos($text, $subject) !== false) {
                return $subject;
            }
        }

        return '其他';
    }

    private function normalizeSubject(mixed $value): string
    {
        $subject = is_string($value) ? trim($value) : '';
        if (in_array($subject, self::SUBJECTS, true)) {
            return $subject;
        }

        return $this->guessSubject($subject !== '' ? $subject : '其他');
    }

    private function normalizeDate(mixed $value, string $todayDate): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                CarbonImmutable::createFromFormat('!Y-m-d', $value);

                return $value;
            } catch (\Throwable) {
                // fall through
            }
        }

        return $todayDate;
    }

    private function guessDate(string $text, string $todayDate): string
    {
        if (preg_match('/(\d{4})[年\-\/.](\d{1,2})[月\-\/.](\d{1,2})/u', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        $today = CarbonImmutable::createFromFormat('!Y-m-d', $todayDate);
        if (mb_strpos($text, '今天') !== false || mb_strpos($text, '今日') !== false) {
            return $todayDate;
        }
        if (mb_strpos($text, '明天') !== false) {
            return $today->addDay()->toDateString();
        }

        $map = [
            '周一' => 1, '星期一' => 1, '周二' => 2, '星期二' => 2,
            '周三' => 3, '星期三' => 3, '周四' => 4, '星期四' => 4,
            '周五' => 5, '星期五' => 5, '周六' => 6, '星期六' => 6,
            '周日' => 7, '星期日' => 7, '周天' => 7,
        ];
        foreach ($map as $label => $iso) {
            if (mb_strpos($text, $label) !== false) {
                $diff = $iso - $today->dayOfWeekIso;
                if ($diff < 0) {
                    $diff += 7;
                }

                return $today->addDays($diff)->toDateString();
            }
        }

        return $todayDate;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $content): ?array
    {
        $trimmed = trim($content);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/us', $trimmed, $m)) {
            $trimmed = $m[1];
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
