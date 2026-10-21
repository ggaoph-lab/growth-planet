<?php

namespace App\Modules\English\Services;

use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Support\Facades\DB;

/** Usage examples are companion material, never separately rewarded vocabulary cards. */
class EnglishWordUsage
{
    public function __construct(private EnglishAiCoach $coach) {}

    public function attach(ChildProfile $child, string $date, array $cards): array
    {
        $record = $this->coach->findRecord($child, 'word_usage_day', $date);
        $saved = $this->index($record);

        return array_map(function (array $card) use ($saved): array {
            $word = strtolower((string) $card['text']);
            $card['usage'] = $this->validate($word, $saved[$word] ?? []) ?? $this->fallback($word);

            return $card;
        }, $cards);
    }

    /** Called only by the background daily preparation, never by plan/answer requests. */
    public function prepare(ChildProfile $child, string $date, array $cards): array
    {
        $record = $this->coach->findRecord($child, 'word_usage_day', $date);
        $saved = $this->index($record);
        $missing = array_values(array_filter($cards, fn ($card) => ! isset($saved[strtolower($card['text'])])));
        if ($missing !== []) {
            $reply = $this->coach->chatJson($child, [
                ['role' => 'system', 'content' => 'For each English word, give ONE natural phrase/collocation (not a complete sentence) and ONE short complete example sentence for a child age 5–8. Both must contain the exact target word. Preserve its given meaning and word form. Use simple English and matching Simplified Chinese translations. Reply JSON only: {"items":[{"word":"bird","phrase":{"text":"a little bird","meaning":"一只小鸟"},"sentence":{"text":"I see a little bird.","meaning":"我看见一只小鸟。"}}]}. Phrase: 2–8 words, no ending punctuation. Sentence: 3–18 words with ending punctuation. No personal data.'],
                ['role' => 'user', 'content' => json_encode(['words' => array_map(fn ($card) => ['word' => $card['text'], 'meaning' => $card['meaning'], 'pos' => $card['pos'] ?? ''], $missing)], JSON_UNESCAPED_UNICODE)],
            ]);
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) ($reply['content'] ?? '')));
            $decoded = json_decode($content ?? '', true);
            $allowed = array_fill_keys(array_map(fn ($card) => strtolower($card['text']), $missing), true);
            foreach (is_array($decoded['items'] ?? null) ? $decoded['items'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $word = strtolower(trim((string) ($row['word'] ?? '')));
                $usage = isset($allowed[$word]) ? $this->validate($word, $row) : null;
                if ($usage !== null) {
                    $saved[$word] = ['word' => $word, ...$usage];
                }
            }
            $items = array_values($saved);
            $status = count($saved) >= count($cards) ? 'ai' : ($saved === [] ? 'local' : 'mixed');
            if ($record === null) {
                $this->coach->storeRecord($child, 'word_usage_day', $date, [], $items, $status, $reply['provider'] ?? $this->coach->selectedProvider($child), $reply['model'] ?? null);
            } else {
                DB::table('english_ai_records')->where('id', $record->id)->update(['items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'status' => $status]);
            }
        }

        return $this->attach($child, $date, $cards);
    }

    public function validate(string $word, array $raw): ?array
    {
        $out = [];
        foreach (['phrase' => 8, 'sentence' => 18] as $kind => $maxWords) {
            if (! is_array($raw[$kind] ?? null)) {
                return null;
            }
            $text = trim((string) ($raw[$kind]['text'] ?? ''));
            $meaning = trim((string) ($raw[$kind]['meaning'] ?? ''));
            $count = str_word_count($text);
            if ($text === '' || mb_strlen($text) > 180 || $meaning === '' || mb_strlen($meaning) > 100
                || preg_match('/\p{Han}/u', $meaning) !== 1
                || preg_match('/^[A-Za-z][A-Za-z ,\x27\x{2019}.!?-]*$/u', $text) !== 1
                || preg_match('/(?<![A-Za-z])'.preg_quote($word, '/').'(?![A-Za-z])/i', $text) !== 1
                || $count < ($kind === 'phrase' ? 2 : 3) || $count > $maxWords
                || ($kind === 'phrase' && preg_match('/[.!?]$/', $text))
                || ($kind === 'sentence' && ! preg_match('/[.!?]$/', $text))) {
                return null;
            }
            $out[$kind] = ['text' => $text, 'meaning' => $meaning];
        }

        return $out;
    }

    private function fallback(string $word): ?array
    {
        $row = config('english_word_usage.'.$word);
        if (! is_array($row) || count($row) !== 4) {
            return null;
        }

        return $this->validate($word, ['phrase' => ['text' => $row[0], 'meaning' => $row[1]], 'sentence' => ['text' => $row[2], 'meaning' => $row[3]]]);
    }

    private function index(?object $record): array
    {
        $rows = json_decode((string) ($record->items ?? '[]'), true);
        $saved = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['word'] ?? null) && $this->validate($row['word'], $row) !== null) {
                $saved[strtolower($row['word'])] = $row;
            }
        }

        return $saved;
    }
}
