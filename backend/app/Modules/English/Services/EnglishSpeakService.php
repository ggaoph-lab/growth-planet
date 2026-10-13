<?php

namespace App\Modules\English\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EnglishSpeakService
{
    public const RETENTION_DAYS = 7;

    public const MAX_BYTES = 1_000_000;

    public const MAX_DURATION_MS = 8000;

    /** @var list<string> */
    private const ALLOWED_MIME = [
        'audio/webm',
        'audio/mp4',
        'audio/m4a',
        'audio/mpeg',
        'audio/wav',
        'audio/wave',
        'audio/x-wav',
        'audio/ogg',
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly EnglishAiCoach $coach,
        private readonly EnglishSpeakStt $stt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function prompt(ChildProfile $child): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $target = $this->pickTarget($child, $today);
        $attempt = DB::table('english_speak_attempts')
            ->where('child_id', $child->id)
            ->where('business_date', $today)
            ->first();

        return [
            'available' => $target !== null,
            'business_date' => $today,
            'target' => $target,
            'attempt' => $attempt === null ? null : $this->presentAttempt($attempt),
            'ai_status' => $this->coach->connectionStatus($child, $today),
            'retention_days' => self::RETENTION_DAYS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(ChildProfile $child, UploadedFile $audio, ?int $durationMs = null): array
    {
        $today = $this->clock->today($child->family->timezone)->toDateString();
        $target = $this->pickTarget($child, $today);
        if ($target === null) {
            throw ValidationException::withMessages(['audio' => '今天还没有可跟读的句子，先完成几道题吧。']);
        }

        $this->assertAudio($audio, $durationMs);
        $disk = 'local';
        $mime = strtolower((string) ($audio->getMimeType() ?: $audio->getClientMimeType() ?: 'audio/webm'));
        if ($mime === 'application/octet-stream') {
            $mime = match (strtolower((string) $audio->getClientOriginalExtension())) {
                'm4a', 'mp4' => 'audio/mp4',
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'ogg' => 'audio/ogg',
                default => 'audio/webm',
            };
        }
        $ext = $this->extension($mime);
        $path = sprintf('english-speak/%d/%d/%s.%s', $child->family_id, $child->id, $today, $ext);

        $existing = DB::table('english_speak_attempts')
            ->where('child_id', $child->id)
            ->where('business_date', $today)
            ->first();
        if ($existing !== null) {
            $this->deleteFile((string) $existing->disk, (string) $existing->path);
        }

        Storage::disk($disk)->put($path, file_get_contents($audio->getRealPath()) ?: '');

        $absolute = Storage::disk($disk)->path($path);
        $stt = $this->stt->transcribe((int) $child->family_id, $absolute, $mime);
        $provider = $stt['provider'] ?? null;
        $model = $stt['model'] ?? null;
        $transcript = $stt['transcript'] ?? null;

        $scored = $this->score($child, $target['text'], $transcript);
        $status = match (true) {
            $scored['status'] === 'scored' => 'scored',
            $transcript !== null || $provider === null => 'local',
            default => 'failed',
        };

        $now = $this->clock->now();
        $payload = [
            'child_id' => $child->id,
            'family_id' => $child->family_id,
            'business_date' => $today,
            'target_key' => $target['key'],
            'target_text' => $target['text'],
            'target_meaning' => $target['meaning'],
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'bytes' => (int) $audio->getSize(),
            'duration_ms' => max(0, min(self::MAX_DURATION_MS, (int) ($durationMs ?? 0))),
            'transcript' => $transcript,
            'score' => $scored['score'],
            'feedback' => $scored['feedback'],
            'provider' => $provider ?? $scored['provider'],
            'model' => $model ?? $scored['model'],
            'status' => $status,
            'expires_at' => $now->addDays(self::RETENTION_DAYS),
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $id = DB::table('english_speak_attempts')->insertGetId($payload + ['created_at' => $now]);
        } else {
            DB::table('english_speak_attempts')->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
        }

        $this->upsertAiRecord($child, $today, $target, $transcript, $scored, $status, $provider, $model);

        $row = DB::table('english_speak_attempts')->where('id', $id)->first();

        return $this->presentAttempt($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForParent(ChildProfile $child): array
    {
        $now = $this->clock->now();

        return DB::table('english_speak_attempts')
            ->where('child_id', $child->id)
            ->where('family_id', $child->family_id)
            ->where('expires_at', '>', $now)
            ->orderByDesc('business_date')
            ->limit(14)
            ->get()
            ->map(fn ($row) => $this->presentAttempt($row))
            ->all();
    }

    public function findForParent(ChildProfile $child, int $id): object
    {
        $row = DB::table('english_speak_attempts')
            ->where('id', $id)
            ->where('child_id', $child->id)
            ->where('family_id', $child->family_id)
            ->where('expires_at', '>', $this->clock->now())
            ->first();
        if ($row === null) {
            throw ValidationException::withMessages(['speak' => '找不到这段跟读录音。']);
        }

        return $row;
    }

    public function absolutePath(object $row): string
    {
        $disk = Storage::disk((string) $row->disk);
        if (! $disk->exists((string) $row->path)) {
            throw ValidationException::withMessages(['speak' => '录音文件已过期或不存在。']);
        }

        return $disk->path((string) $row->path);
    }

    public function pruneExpired(): int
    {
        $rows = DB::table('english_speak_attempts')
            ->where('expires_at', '<=', $this->clock->now())
            ->get();
        $count = 0;
        foreach ($rows as $row) {
            $this->deleteFile((string) $row->disk, (string) $row->path);
            DB::table('english_speak_attempts')->where('id', $row->id)->delete();
            $count++;
        }

        return $count;
    }

    /**
     * @return array{key: string, text: string, meaning: string, kind: string}|null
     */
    private function pickTarget(ChildProfile $child, string $date): ?array
    {
        $keys = DB::table('english_learning_answers')
            ->where('child_id', $child->id)
            ->where('business_date', $date)
            ->where('correct', true)
            ->orderByDesc('id')
            ->pluck('item_key')
            ->unique()
            ->values()
            ->all();

        $catalog = collect(config('english_learning.items'))->keyBy('key');
        $sentences = [];
        $words = [];
        foreach ($keys as $key) {
            if (isset($catalog[$key])) {
                $item = $catalog[$key];
                $card = [
                    'key' => (string) $item['key'],
                    'text' => (string) $item['text'],
                    'meaning' => (string) $item['meaning'],
                    'kind' => (string) $item['kind'],
                ];
                if ($card['kind'] === 'sentence') {
                    $sentences[] = $card;
                } else {
                    $words[] = $card;
                }

                continue;
            }
            if (str_starts_with((string) $key, 'grammar:') || str_starts_with((string) $key, 'story:')) {
                $id = (int) substr((string) $key, strpos((string) $key, ':') + 1);
                $draft = DB::table('english_content_drafts')->where('id', $id)->where('child_id', $child->id)->first();
                if ($draft === null) {
                    continue;
                }
                $payload = json_decode((string) $draft->payload, true);
                if (! is_array($payload)) {
                    continue;
                }
                $text = trim((string) ($payload['text'] ?? $payload['prompt'] ?? ''));
                $meaning = trim((string) ($payload['question'] ?? $payload['meaning'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $sentences[] = [
                    'key' => (string) $key,
                    'text' => mb_substr($text, 0, 120),
                    'meaning' => mb_substr($meaning !== '' ? $meaning : '跟读挑战', 0, 80),
                    'kind' => 'sentence',
                ];
            }
            if (str_starts_with((string) $key, 'probe:')) {
                $probe = $this->probeCard($child, $date, (string) $key);
                if ($probe !== null) {
                    if ($probe['kind'] === 'sentence') {
                        $sentences[] = $probe;
                    } else {
                        $words[] = $probe;
                    }
                }
            }
        }

        if ($sentences !== []) {
            return $sentences[0];
        }
        if ($words !== []) {
            // Prefer a real catalog word over a probe slug.
            foreach ($words as $word) {
                if (! str_starts_with($word['key'], 'probe:')) {
                    return $word;
                }
            }

            return $words[0];
        }

        return null;
    }

    /**
     * @return array{key: string, text: string, meaning: string, kind: string}|null
     */
    private function probeCard(ChildProfile $child, string $date, string $key): ?array
    {
        $record = $this->coach->findRecord($child, 'vocabulary_probe', $date);
        if ($record === null) {
            return null;
        }
        $items = json_decode((string) $record->items, true);
        if (! is_array($items)) {
            return null;
        }
        foreach ($items as $item) {
            if (! is_array($item) || (string) ($item['key'] ?? '') !== $key) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            $meaning = trim((string) ($item['meaning'] ?? ''));
            if ($text === '') {
                return null;
            }

            return [
                'key' => $key,
                'text' => mb_substr($text, 0, 120),
                'meaning' => mb_substr($meaning !== '' ? $meaning : '跟读一下', 0, 80),
                'kind' => (string) ($item['kind'] ?? (str_contains($text, ' ') ? 'sentence' : 'word')),
            ];
        }

        return null;
    }

    private function assertAudio(UploadedFile $audio, ?int $durationMs): void
    {
        if (! $audio->isValid()) {
            throw ValidationException::withMessages(['audio' => '录音上传失败，请再试一次。']);
        }
        if ($audio->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['audio' => '录音有点长，请说得短一些（大约 8 秒内）。']);
        }
        $mime = strtolower((string) ($audio->getMimeType() ?: $audio->getClientMimeType()));
        $ext = strtolower((string) $audio->getClientOriginalExtension());
        $okMime = in_array($mime, self::ALLOWED_MIME, true)
            || ($mime === 'application/octet-stream' && in_array($ext, ['webm', 'm4a', 'mp3', 'mp4', 'wav', 'ogg'], true));
        if (! $okMime) {
            throw ValidationException::withMessages(['audio' => '暂不支持这种录音格式。']);
        }
        if ($durationMs !== null && $durationMs > self::MAX_DURATION_MS + 500) {
            throw ValidationException::withMessages(['audio' => '录音请控制在 8 秒以内。']);
        }
    }

    /**
     * @return array{score: int, feedback: string, status: string, provider: ?string, model: ?string}
     */
    private function score(ChildProfile $child, string $target, ?string $transcript): array
    {
        $local = $this->localScore($target, $transcript);

        if ($transcript === null || trim($transcript) === '') {
            return [
                'score' => $local['score'],
                'feedback' => '已收录你的声音，明天可以再练一遍。',
                'status' => 'local',
                'provider' => null,
                'model' => null,
            ];
        }

        $reply = $this->coach->chatJson($child, [
            [
                'role' => 'system',
                'content' => 'You coach a young child reading one English phrase aloud. Reply JSON only: {"score":1-5,"feedback":"short Simplified Chinese encouragement under 40 characters"}. Be kind. Score by how close the transcript is to the target, not accent perfection. No personal data.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'target' => $target,
                    'transcript' => $transcript,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        if ($reply === null) {
            return [
                'score' => $local['score'],
                'feedback' => $local['feedback'],
                'status' => 'local',
                'provider' => null,
                'model' => null,
            ];
        }

        $content = trim($reply['content']);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true);
        $score = is_array($decoded) ? (int) ($decoded['score'] ?? 0) : 0;
        $feedback = is_array($decoded) ? trim((string) ($decoded['feedback'] ?? '')) : '';
        if ($score < 1 || $score > 5 || $feedback === '' || mb_strlen($feedback) > 80) {
            return [
                'score' => $local['score'],
                'feedback' => $local['feedback'],
                'status' => 'local',
                'provider' => $reply['provider'],
                'model' => $reply['model'],
            ];
        }

        return [
            'score' => $score,
            'feedback' => $feedback,
            'status' => 'scored',
            'provider' => $reply['provider'],
            'model' => $reply['model'],
        ];
    }

    /**
     * @return array{score: int, feedback: string}
     */
    private function localScore(string $target, ?string $transcript): array
    {
        if ($transcript === null || trim($transcript) === '') {
            return ['score' => 3, 'feedback' => '勇敢开口了，真棒！'];
        }
        similar_text(strtolower(trim($target)), strtolower(trim($transcript)), $percent);
        $score = match (true) {
            $percent >= 85 => 5,
            $percent >= 65 => 4,
            $percent >= 40 => 3,
            $percent >= 20 => 2,
            default => 1,
        };

        return [
            'score' => $score,
            'feedback' => $score >= 4 ? '跟读很接近，继续加油！' : '再听一遍范读，慢慢说也可以。',
        ];
    }

    /**
     * @param  array{key: string, text: string, meaning: string, kind: string}  $target
     * @param  array{score: int, feedback: string, status: string, provider: ?string, model: ?string}  $scored
     */
    private function upsertAiRecord(
        ChildProfile $child,
        string $date,
        array $target,
        ?string $transcript,
        array $scored,
        string $status,
        ?string $provider,
        ?string $model,
    ): void {
        $snapshot = [
            'target_key' => $target['key'],
            'target_text' => $target['text'],
            'has_transcript' => $transcript !== null,
        ];
        $items = [
            'score' => $scored['score'],
            'feedback' => $scored['feedback'],
            'transcript' => $transcript,
        ];
        $recordStatus = $status === 'scored' ? 'ai' : ($status === 'local' ? 'local' : 'local');
        $existing = $this->coach->findRecord($child, 'speak_score', $date);
        if ($existing !== null) {
            DB::table('english_ai_records')->where('id', $existing->id)->update([
                'provider' => $provider ?? $scored['provider'],
                'model' => $model ?? $scored['model'],
                'profile_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'status' => $recordStatus,
            ]);

            return;
        }
        $this->coach->storeRecord(
            $child,
            'speak_score',
            $date,
            $snapshot,
            $items,
            $recordStatus,
            $provider ?? $scored['provider'],
            $model ?? $scored['model'],
        );
    }

    private function deleteFile(string $disk, string $path): void
    {
        if ($path === '') {
            return;
        }
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/wave', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            default => 'webm',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttempt(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'id' => (int) $row->id,
            'business_date' => (string) $row->business_date,
            'target_key' => (string) $row->target_key,
            'target_text' => (string) $row->target_text,
            'target_meaning' => (string) $row->target_meaning,
            'score' => $row->score !== null ? (int) $row->score : null,
            'feedback' => $row->feedback,
            'status' => (string) $row->status,
            'duration_ms' => (int) $row->duration_ms,
            'expires_at' => (string) $row->expires_at,
            'created_at' => (string) $row->created_at,
        ];
    }
}
