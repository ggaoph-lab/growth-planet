<?php

namespace App\Modules\English\Services;

/**
 * Validates AI- or ladder-produced English Lab cards before they reach a child.
 */
class EnglishContentGate
{
    /**
     * @param  array<string, true>  $avoid
     * @return array<string, mixed>|null
     */
    public function probeCard(array $raw, array $avoid, int $band = 1): ?array
    {
        $text = $this->expression((string) ($raw['text'] ?? ''));
        $meaning = trim((string) ($raw['meaning'] ?? ''));
        if ($text === '' || $meaning === '' || mb_strlen($meaning) > 20) {
            return null;
        }
        if (preg_match('/^[A-Za-z][A-Za-z \'.?!,-]{0,39}$/', $text) !== 1) {
            return null;
        }
        if (isset($avoid[strtolower($text)])) {
            return null;
        }
        if (! $this->fitsBand($text, $band)) {
            return null;
        }

        $kind = strtolower(trim((string) ($raw['kind'] ?? '')));
        if (! in_array($kind, ['word', 'sentence'], true)) {
            $kind = str_contains($text, ' ') ? 'sentence' : 'word';
        }

        $options = $this->chineseOptions($raw['options'] ?? [], $meaning);
        if ($options === null) {
            return null;
        }

        $emoji = trim((string) ($raw['emoji'] ?? '✨'));
        if ($emoji === '' || mb_strlen($emoji) > 8) {
            $emoji = '✨';
        }

        $ipa = $this->ipa((string) ($raw['ipa'] ?? ''), $kind);
        $pos = $this->pos((string) ($raw['pos'] ?? ''), $kind);

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        if ($slug === '') {
            return null;
        }

        return [
            'key' => 'probe:'.mb_substr($slug, 0, 50),
            'theme' => $kind === 'sentence' ? '生活短句' : '词汇小测试',
            'kind' => $kind,
            'text' => $text,
            'meaning' => $meaning,
            'emoji' => $emoji,
            'ipa' => $ipa,
            'pos' => $pos,
            'options' => $options,
            'review' => false,
            'stage' => 0,
            'probe' => true,
        ];
    }

    public function ipa(string $ipa, string $kind = 'word'): string
    {
        $ipa = trim($ipa);
        if ($kind === 'sentence' || $ipa === '') {
            return '';
        }
        if (mb_strlen($ipa) > 48) {
            return '';
        }
        // Keep printable IPA-like marks; drop CJK and control characters.
        if (preg_match('/[\x{4e00}-\x{9fff}\x00-\x1f]/u', $ipa) === 1) {
            return '';
        }

        return $ipa;
    }

    public function pos(string $pos, string $kind = 'word'): string
    {
        if ($kind !== 'word') {
            return '';
        }
        $pos = trim($pos);
        $allowed = ['n.', 'v.', 'adj.', 'adv.', 'prep.', 'pron.', 'conj.', 'interj.', 'num.', 'art.'];
        if (in_array($pos, $allowed, true)) {
            return $pos;
        }
        $map = [
            'noun' => 'n.', 'n' => 'n.', '名词' => 'n.',
            'verb' => 'v.', 'v' => 'v.', '动词' => 'v.',
            'adj' => 'adj.', 'adjective' => 'adj.', '形容词' => 'adj.',
            'adv' => 'adv.', 'adverb' => 'adv.', '副词' => 'adv.',
        ];
        $key = strtolower(rtrim($pos, '.'));

        return $map[$key] ?? $map[$pos] ?? '';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public function challengeDraft(string $kind, array $raw, int $band = 1): ?array
    {
        if (! in_array($kind, ['grammar', 'story'], true)) {
            return null;
        }

        $prompt = trim((string) ($raw['prompt'] ?? ''));
        $question = trim((string) ($raw['question'] ?? ''));
        $answer = trim((string) ($raw['answer'] ?? ''));
        $tip = trim((string) ($raw['tip'] ?? ''));
        $emoji = trim((string) ($raw['emoji'] ?? ($kind === 'story' ? '📖' : '🧩')));

        if ($prompt === '' || $question === '' || $answer === '') {
            return null;
        }
        if (mb_strlen($prompt) > 280 || mb_strlen($question) > 120 || mb_strlen($tip) > 120) {
            return null;
        }
        if ($emoji === '' || mb_strlen($emoji) > 8) {
            $emoji = $kind === 'story' ? '📖' : '🧩';
        }

        if ($kind === 'story') {
            $words = preg_split('/\s+/u', trim($prompt)) ?: [];
            if (count($words) > 40) {
                return null;
            }
        }

        if (preg_match('/[A-Za-z]/', $prompt) !== 1 && preg_match('/[A-Za-z]/', $question) !== 1) {
            return null;
        }

        $options = $this->chineseOptions($raw['options'] ?? [], $answer, allowEnglish: true);
        if ($options === null) {
            return null;
        }
        if (! in_array($answer, $options, true)) {
            return null;
        }

        $theme = $kind === 'story' ? '短故事' : '语法小练';
        $text = $kind === 'story' ? $prompt : (string) ($raw['text'] ?? $prompt);
        if ($text === '' || mb_strlen($text) > 280) {
            return null;
        }
        if ($kind === 'grammar' && ! $this->fitsBand($text, max(1, $band))) {
            // Grammar prompts may be short Chinese+English mixes; only soft-check Latin tokens.
        }

        return [
            'kind' => $kind,
            'theme' => $theme,
            'text' => $text,
            'prompt' => $prompt,
            'question' => $question,
            'meaning' => $question,
            'emoji' => $emoji,
            'options' => $options,
            'answer' => $answer,
            'tip' => $tip !== '' ? $tip : '再读一遍，选出最合适的答案。',
            'review' => false,
            'stage' => 0,
            'probe' => false,
            'challenge' => true,
        ];
    }

    /**
     * @param  list<mixed>  $rawOptions
     * @return list<string>|null
     */
    public function chineseOptions(mixed $rawOptions, string $correct, bool $allowEnglish = false): ?array
    {
        $options = [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $option) {
                $option = trim((string) $option);
                if ($option === '' || mb_strlen($option) > 40) {
                    continue;
                }
                if (! $allowEnglish && preg_match('/[\x{4e00}-\x{9fff}]/u', $option) !== 1) {
                    continue;
                }
                if (! in_array($option, $options, true)) {
                    $options[] = $option;
                }
            }
        }
        if (! in_array($correct, $options, true)) {
            array_unshift($options, $correct);
        }
        $options = array_slice(array_values(array_unique($options)), 0, 3);
        if (count($options) < 3 || ! in_array($correct, $options, true)) {
            return null;
        }

        return $options;
    }

    public function expression(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '' || mb_strlen($text) > 40) {
            return '';
        }
        if (! str_contains($text, ' ')) {
            return strtolower($text);
        }

        return $text;
    }

    public function fitsBand(string $text, int $band): bool
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/[.!?,]/', '', $text) ?? $text)) ?: [];
        $count = count(array_filter($words, fn ($word) => $word !== ''));
        // Everyday sentences like "I see a bird." need a little more room than single words.
        $maxWords = match (true) {
            $band <= 1 => 5,
            $band === 2 => 6,
            $band === 3 => 8,
            default => 12,
        };
        if ($count > $maxWords) {
            return false;
        }
        if ($count === 1 && mb_strlen($text) > 18 && $band <= 2) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $candidateKeys
     * @param  list<mixed>  $orderedKeys
     * @return list<string>|null
     */
    public function reorderKeys(array $candidateKeys, array $orderedKeys): ?array
    {
        $wanted = array_values(array_unique(array_map('strval', $candidateKeys)));
        $seen = [];
        $ordered = [];
        foreach ($orderedKeys as $key) {
            $key = (string) $key;
            if (! in_array($key, $wanted, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordered[] = $key;
        }
        if (count($ordered) !== count($wanted)) {
            return null;
        }
        sort($wanted);
        $check = $ordered;
        sort($check);
        if ($wanted !== $check) {
            return null;
        }

        return $ordered;
    }
}
