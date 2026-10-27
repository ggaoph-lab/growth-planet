<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Core\Settings\Settings;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Models\PoetryPoemEnrichment;
use App\Modules\Task\Services\QwenClient;
use Illuminate\Support\Facades\Log;

/**
 * Qwen-backed story / image-prompt / recitation coach for grade-1 poetry.
 * Falls back to deterministic local heuristics when AI is off or unavailable.
 */
class PoetryAiCoach
{
    public function __construct(
        private readonly QwenClient $qwen,
        private readonly Settings $settings,
    ) {}

    public function storyFor(PoetryPoem $poem, int $familyId): string
    {
        $enrichment = $this->ensureEnrichment($poem, $familyId);

        return (string) ($enrichment->story_zh ?: $this->fallbackStory($poem));
    }

    /**
     * Kid-friendly meaning for each poem line (same order as lines()).
     *
     * @return list<string>
     */
    public function lineMeaningsFor(PoetryPoem $poem, int $familyId): array
    {
        $enrichment = $this->ensureEnrichment($poem, $familyId);
        $lines = $poem->lines();
        $meanings = $enrichment->line_meanings;
        if ($this->meaningsNeedRefresh(is_array($meanings) ? $meanings : null, count($lines))) {
            return $this->fallbackLineMeanings($poem);
        }

        return array_values(array_map(
            static fn ($m) => trim((string) $m),
            $meanings,
        ));
    }

    public function imagePromptFor(PoetryPoem $poem, int $familyId): string
    {
        $enrichment = $this->ensureEnrichment($poem, $familyId);

        return (string) ($enrichment->image_prompt ?: $this->fallbackImagePrompt($poem));
    }

    /**
     * @return array{
     *   passed: bool,
     *   accuracy_percent: int,
     *   feedback: string,
     *   diff: array{missing: list<string>, extra: list<string>, wrong_order: bool},
     *   provider: string
     * }
     */
    public function checkRecitation(PoetryPoem $poem, string $transcript, int $familyId): array
    {
        $expected = $poem->plainText();
        $got = $this->normalizeTranscript($transcript);
        $local = $this->localDiff($expected, $got);

        if (! $this->aiEnabled($familyId) || ! $this->qwenConfigured($familyId)) {
            return [
                'passed' => $local['accuracy_percent'] >= 90,
                'accuracy_percent' => $local['accuracy_percent'],
                'feedback' => $this->localFeedback($local, $poem),
                'diff' => [
                    'missing' => $local['missing'],
                    'extra' => $local['extra'],
                    'wrong_order' => $local['wrong_order'],
                ],
                'provider' => 'local',
            ];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => self::RECITATION_SYSTEM_PROMPT,
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $poem->title,
                    'author' => $poem->author,
                    'original' => implode("\n", $poem->lines()),
                    'child_recited' => $transcript,
                    'local_diff' => $local,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $result = $this->qwen->chatJson($messages, $this->qwenConfig($familyId));
        $parsed = $this->parseJsonObject($result['content'] ?? null);
        if ($parsed === null) {
            return [
                'passed' => $local['accuracy_percent'] >= 90,
                'accuracy_percent' => $local['accuracy_percent'],
                'feedback' => $this->localFeedback($local, $poem),
                'diff' => [
                    'missing' => $local['missing'],
                    'extra' => $local['extra'],
                    'wrong_order' => $local['wrong_order'],
                ],
                'provider' => 'local',
            ];
        }

        $accuracy = (int) ($parsed['accuracy_percent'] ?? $local['accuracy_percent']);
        $accuracy = max(0, min(100, $accuracy));
        $passed = (bool) ($parsed['passed'] ?? ($accuracy >= 90));
        $feedback = trim((string) ($parsed['feedback'] ?? ''));
        if ($feedback === '') {
            $feedback = $this->localFeedback($local, $poem);
        }

        return [
            'passed' => $passed,
            'accuracy_percent' => $accuracy,
            'feedback' => $feedback,
            'diff' => [
                'missing' => array_values(array_map('strval', $parsed['missing'] ?? $local['missing'])),
                'extra' => array_values(array_map('strval', $parsed['extra'] ?? $local['extra'])),
                'wrong_order' => (bool) ($parsed['wrong_order'] ?? $local['wrong_order']),
            ],
            'provider' => 'qwen',
        ];
    }

    private function ensureEnrichment(PoetryPoem $poem, int $familyId): PoetryPoemEnrichment
    {
        $existing = PoetryPoemEnrichment::query()->where('poem_id', $poem->id)->first();
        $lineCount = count($poem->lines());
        $meaningsOk = ! $this->meaningsNeedRefresh(
            is_array($existing?->line_meanings) ? $existing->line_meanings : null,
            $lineCount,
        );
        if ($existing !== null && $existing->story_zh && $existing->image_prompt && $meaningsOk) {
            return $existing;
        }

        $story = $existing?->story_zh;
        $prompt = $existing?->image_prompt;
        $meanings = $meaningsOk ? $existing->line_meanings : null;
        $provider = $existing?->ai_provider;
        $model = $existing?->ai_model;
        $aiOn = $this->aiEnabled($familyId) && $this->qwenConfigured($familyId);

        if ($aiOn && ($story === null || $prompt === null || $meanings === null)) {
            // Prefer a focused line-meaning call when story/image already exist.
            if ($meanings === null && $story !== null && $prompt !== null) {
                $onlyMeanings = $this->generateLineMeaningsViaQwen($poem, $familyId);
                if ($onlyMeanings !== null) {
                    $meanings = $onlyMeanings['line_meanings'];
                    $provider = 'qwen';
                    $model = $onlyMeanings['model'] ?? $model;
                }
            }

            if ($story === null || $prompt === null || $meanings === null) {
                $generated = $this->generateEnrichmentViaQwen($poem, $familyId);
                if ($generated !== null) {
                    $story = $story ?: $generated['story_zh'];
                    $prompt = $prompt ?: $generated['image_prompt'];
                    $meanings = $meanings ?: $generated['line_meanings'];
                    $provider = 'qwen';
                    $model = $generated['model'];
                }
            }

            if ($meanings === null) {
                $onlyMeanings = $this->generateLineMeaningsViaQwen($poem, $familyId);
                if ($onlyMeanings !== null) {
                    $meanings = $onlyMeanings['line_meanings'];
                    $provider = 'qwen';
                    $model = $onlyMeanings['model'] ?? $model;
                }
            }
        }

        $story = $story ?: $this->fallbackStory($poem);
        $prompt = $prompt ?: $this->fallbackImagePrompt($poem);
        $meanings = ! $this->meaningsNeedRefresh(is_array($meanings) ? $meanings : null, $lineCount)
            ? array_values(array_map(static fn ($m) => trim((string) $m), $meanings))
            : $this->fallbackLineMeanings($poem);

        return PoetryPoemEnrichment::query()->updateOrCreate(
            ['poem_id' => $poem->id],
            [
                'story_zh' => $story,
                'line_meanings' => $meanings,
                'image_prompt' => $prompt,
                'ai_provider' => $provider,
                'ai_model' => $model,
            ],
        );
    }

    /**
     * @return array{story_zh: string, image_prompt: string, line_meanings: list<string>, model: ?string}|null
     */
    private function generateEnrichmentViaQwen(PoetryPoem $poem, int $familyId): ?array
    {
        $lines = $poem->lines();
        $messages = [
            [
                'role' => 'system',
                'content' => self::ENRICHMENT_SYSTEM_PROMPT,
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $poem->title,
                    'author' => $poem->author,
                    'dynasty' => $poem->dynasty,
                    'lines' => $lines,
                    'content' => implode("\n", $lines),
                    'line_count' => count($lines),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $result = $this->qwen->chatJson($messages, $this->qwenConfig($familyId));
        $parsed = $this->parseJsonObject($result['content'] ?? null);
        if ($parsed === null) {
            return null;
        }
        $story = trim((string) ($parsed['story_zh'] ?? ''));
        $prompt = trim((string) ($parsed['image_prompt'] ?? ''));
        $meanings = $this->normalizeGeneratedMeanings($parsed['line_meanings'] ?? null, $lines);
        if ($story === '' || $prompt === '' || $meanings === null) {
            Log::warning('poetry.enrichment_incomplete', ['poem_id' => $poem->id]);

            return null;
        }

        return [
            'story_zh' => $story,
            'image_prompt' => $prompt,
            'line_meanings' => $meanings,
            'model' => isset($result['model']) ? (string) $result['model'] : null,
        ];
    }

    /**
     * @return array{line_meanings: list<string>, model: ?string}|null
     */
    private function generateLineMeaningsViaQwen(PoetryPoem $poem, int $familyId): ?array
    {
        $lines = $poem->lines();
        $messages = [
            [
                'role' => 'system',
                'content' => self::LINE_MEANINGS_SYSTEM_PROMPT,
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $poem->title,
                    'author' => $poem->author,
                    'dynasty' => $poem->dynasty,
                    'lines' => $lines,
                    'line_count' => count($lines),
                    'audience' => '中国一年级小朋友（6–7岁）',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $result = $this->qwen->chatJson($messages, $this->qwenConfig($familyId));
        $parsed = $this->parseJsonObject($result['content'] ?? null);
        if ($parsed === null) {
            return null;
        }
        $meanings = $this->normalizeGeneratedMeanings($parsed['line_meanings'] ?? null, $lines);
        if ($meanings === null) {
            Log::warning('poetry.line_meanings_incomplete', ['poem_id' => $poem->id]);

            return null;
        }

        return [
            'line_meanings' => $meanings,
            'model' => isset($result['model']) ? (string) $result['model'] : null,
        ];
    }

    /**
     * @param  mixed  $raw
     * @param  list<string>  $lines
     * @return list<string>|null
     */
    private function normalizeGeneratedMeanings(mixed $raw, array $lines): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $meanings = [];
        foreach ($raw as $m) {
            $meanings[] = trim((string) $m);
        }
        if ($this->meaningsNeedRefresh($meanings, count($lines))) {
            return null;
        }

        return array_values($meanings);
    }

    /** @param  list<string>|null  $meanings */
    private function meaningsNeedRefresh(?array $meanings, int $lineCount): bool
    {
        if ($meanings === null || count($meanings) !== $lineCount) {
            return true;
        }
        foreach ($meanings as $m) {
            $t = trim((string) $m);
            if ($t === '') {
                return true;
            }
            if (
                str_contains($t, '慢慢想画面')
                || str_contains($t, '体会诗人')
                || str_contains($t, '体会意思')
                || str_contains($t, '慢慢体会')
            ) {
                return true;
            }
            // Prefer fuller kid explanations; short blurbs get regenerated/replaced.
            if (mb_strlen($t) < 28) {
                return true;
            }
        }

        return false;
    }

    private function aiEnabled(int $familyId): bool
    {
        return (bool) $this->settings->get('poetry.allow_ai', $familyId);
    }

    private function qwenConfigured(int $familyId): bool
    {
        return $this->qwen->isConfigured($this->qwenConfig($familyId));
    }

    /** @return array{api_key?: string, model?: string} */
    private function qwenConfig(int $familyId): array
    {
        $key = trim((string) $this->settings->get('ai.qwen_api_key', $familyId));
        $model = trim((string) $this->settings->get('ai.qwen_model', $familyId));

        return array_filter([
            'api_key' => $key !== '' ? $key : null,
            'model' => $model !== '' ? $model : null,
        ], fn ($v) => $v !== null);
    }

    private function fallbackStory(PoetryPoem $poem): string
    {
        $author = $poem->author ?: '一位诗人';
        $title = $poem->title;

        return "很久很久以前，{$author}写了一首叫《{$title}》的小诗。诗里讲的是：".implode('', $poem->lines()).' 我们一起慢慢读，想象画面，就像听故事一样。';
    }

    private function fallbackImagePrompt(PoetryPoem $poem): string
    {
        return "古诗《{$poem->title}》意境插画，".implode(' ', $poem->lines());
    }

    /** @return list<string> */
    private function fallbackLineMeanings(PoetryPoem $poem): array
    {
        $lines = $poem->lines();
        $builtin = $this->builtinLineMeanings($poem);
        if ($builtin !== null && count($builtin) === count($lines)) {
            return $builtin;
        }

        // Last resort: still try to sound like a kid explanation, not a vague prompt.
        return array_map(
            static fn (string $line) => '这句说的是：'.preg_replace('/[，。！？、；：]/u', '', $line).'。想象一下当时的画面。',
            $lines,
        );
    }

    /** @return list<string>|null */
    private function builtinLineMeanings(PoetryPoem $poem): ?array
    {
        $key = (string) ($poem->external_id ?? '');
        if ($key === '') {
            return null;
        }
        static $map = null;
        if ($map === null) {
            $path = base_path('app/Modules/Poetry/data/grade1-line-meanings.php');
            $map = is_file($path) ? require $path : [];
            if (! is_array($map)) {
                $map = [];
            }
        }
        $row = $map[$key] ?? null;
        if (! is_array($row)) {
            return null;
        }

        return array_values(array_map(static fn ($m) => trim((string) $m), $row));
    }

    private function normalizeTranscript(string $text): string
    {
        $text = preg_replace('/\s+/u', '', $text) ?? '';

        return preg_replace('/[，。！？、；：,.!?;:\"\'“”‘’《》【】（）()]/u', '', $text) ?? '';
    }

    /**
     * @return array{accuracy_percent: int, missing: list<string>, extra: list<string>, wrong_order: bool}
     */
    private function localDiff(string $expected, string $got): array
    {
        $expChars = preg_split('//u', $expected, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $gotChars = preg_split('//u', $got, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $expCount = array_count_values($expChars);
        $gotCount = array_count_values($gotChars);

        $missing = [];
        $extra = [];
        foreach ($expCount as $ch => $need) {
            $have = $gotCount[$ch] ?? 0;
            for ($i = $have; $i < $need; $i++) {
                $missing[] = (string) $ch;
            }
        }
        foreach ($gotCount as $ch => $have) {
            $need = $expCount[$ch] ?? 0;
            for ($i = $need; $i < $have; $i++) {
                $extra[] = (string) $ch;
            }
        }

        $wrongOrder = false;
        if ($missing === [] && $extra === [] && $expected !== '' && $got !== $expected) {
            $wrongOrder = true;
        }

        $total = max(1, count($expChars));
        $matched = 0;
        $tmpGot = $gotCount;
        foreach ($expChars as $ch) {
            if (($tmpGot[$ch] ?? 0) > 0) {
                $matched++;
                $tmpGot[$ch]--;
            }
        }
        $accuracy = (int) round(($matched / $total) * 100);
        if ($wrongOrder) {
            $accuracy = min($accuracy, 85);
        }

        return [
            'accuracy_percent' => max(0, min(100, $accuracy)),
            'missing' => array_values(array_unique($missing)),
            'extra' => array_values(array_unique($extra)),
            'wrong_order' => $wrongOrder,
        ];
    }

    /**
     * @param  array{accuracy_percent: int, missing: list<string>, extra: list<string>, wrong_order: bool}  $diff
     */
    private function localFeedback(array $diff, PoetryPoem $poem): string
    {
        if ($diff['accuracy_percent'] >= 95 && ! $diff['wrong_order']) {
            return "太棒了！《{$poem->title}》背得又准又好听，给你点个大大的赞！";
        }
        if ($diff['accuracy_percent'] >= 90) {
            return '几乎完美啦！再轻轻练一遍，就会更稳哦。';
        }
        $hints = [];
        if ($diff['missing'] !== []) {
            $hints[] = '好像少了「'.implode('', array_slice($diff['missing'], 0, 4)).'」这些字';
        }
        if ($diff['extra'] !== []) {
            $hints[] = '多说了「'.implode('', array_slice($diff['extra'], 0, 4)).'」';
        }
        if ($diff['wrong_order']) {
            $hints[] = '字都对了，但顺序有点乱';
        }
        $hint = $hints !== [] ? implode('；', $hints).'。' : '';

        return "加油！{$hint}我们再看一眼原文，慢慢跟着读一遍就更好了。";
    }

    /** @return array<string, mixed>|null */
    private function parseJsonObject(?string $content): ?array
    {
        if ($content === null || trim($content) === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public const ENRICHMENT_SYSTEM_PROMPT = <<<'PROMPT'
你是面向中国一年级（6–7岁）孩子的古诗词助手。根据给定古诗，输出严格 JSON（不要 markdown）：
{
  "story_zh": "把古诗改写成 120–220 字的白话小故事，口语化、生动、有画面，不用文言，不出现恐吓内容",
  "line_meanings": ["与 lines 一一对应的详细儿童讲解"],
  "image_prompt": "英文或中文皆可的 AI 绘画提示词，抓住意境与主要景物，末尾不要重复风格后缀"
}
line_meanings 要求：
- 数组长度必须等于诗句行数，顺序与 lines 一致
- 每句 35–80 个汉字，比简单翻译更详细一点
- 先说清楚画面里有谁、在干什么、看到什么，再轻轻点出心情或原因
- 难字、典故要用一年级熟悉的词解释（如「疑是」→「还以为／好像是」）
- 禁止只重复原句；禁止「慢慢体会」「想象画面」「体会诗人」等空话
- 像温柔的老师把这句话讲给坐在身边的小朋友听
PROMPT;

    public const LINE_MEANINGS_SYSTEM_PROMPT = <<<'PROMPT'
你是一年级语文老师。请把古诗每一句讲成适龄小朋友一听就懂、又够详细的解释。输出严格 JSON（不要 markdown）：
{
  "line_meanings": ["第1句解释", "第2句解释", "..."]
}
硬性要求：
1. line_meanings 长度必须等于给定 line_count，顺序与 lines 完全对应
2. 每句 35–80 个汉字：说明谁/什么在做什么、眼前是什么画面，必要时补一句心情或为什么
3. 用口语短句，像讲故事；难字要换成孩子熟悉的说法
4. 不要只抄原句；不要文言；不要恐吓内容
5. 禁止「慢慢体会」「体会诗人」「好好想象」等空洞套话
PROMPT;

    public const RECITATION_SYSTEM_PROMPT = <<<'PROMPT'
你是温柔的一年级古诗背诵老师。对比原文与孩子背诵文本，指出错字、漏字、多字或顺序颠倒。输出严格 JSON：
{
  "passed": true/false,
  "accuracy_percent": 0-100,
  "missing": ["漏的字"],
  "extra": ["多的字"],
  "wrong_order": true/false,
  "feedback": "2–4句温柔鼓励的中文评语，先肯定再轻轻纠错，适合读给孩子听"
}
准确率≥90且无明显顺序错误时可 passed=true。不要输出 JSON 以外的文字。
PROMPT;
}
