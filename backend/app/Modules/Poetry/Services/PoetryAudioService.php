<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Poetry\Models\PoetryAudioCache;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\QwenTtsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Poem read-aloud library: expressive script → ElevenLabs (preferred) → disk cache.
 * Subsequent plays reuse the stored MP3 without calling TTS again.
 */
class PoetryAudioService
{
    public function __construct(
        private readonly PoetryCatalog $catalog,
        private readonly PoetryAiCoach $ai,
        private readonly FamilyAiConfig $familyAi,
        private readonly QwenTtsService $tts,
    ) {}

    /**
     * @return array{
     *   clip: string,
     *   url: ?string,
     *   mime: string,
     *   provider: ?string,
     *   cached: bool,
     *   status: string,
     *   script: string
     * }
     */
    public function ensureClip(int $poemId, string $clip, int $familyId): array
    {
        $poem = $this->catalog->findOrFail($poemId);
        $clipKey = $this->normalizeClipKey($clip, $poem);
        $script = $this->scriptFor($poem, $clipKey, $familyId);
        $scriptHash = $this->scriptHash($script);
        $voiceTag = $this->voiceTag($familyId);

        $existing = PoetryAudioCache::query()
            ->where('poem_id', $poem->id)
            ->where('clip_key', $clipKey)
            ->first();

        if (
            $existing !== null
            && $existing->status === 'ready'
            && $existing->script_hash === $scriptHash
            && $this->voiceMatches($existing, $voiceTag)
            && $this->fileExists($existing)
        ) {
            return $this->payload($existing, $script, cached: true);
        }

        $lockKey = "poetry-audio:{$poem->id}:{$clipKey}";
        $lock = Cache::lock($lockKey, 60);
        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            $existing = PoetryAudioCache::query()
                ->where('poem_id', $poem->id)
                ->where('clip_key', $clipKey)
                ->first();
            if ($existing && $existing->status === 'ready' && $this->fileExists($existing)) {
                return $this->payload($existing, $script, cached: true);
            }

            return [
                'clip' => $clipKey,
                'url' => null,
                'mime' => 'audio/mpeg',
                'provider' => null,
                'cached' => false,
                'status' => 'busy',
                'script' => $script,
            ];
        }

        try {
            $existing = PoetryAudioCache::query()
                ->where('poem_id', $poem->id)
                ->where('clip_key', $clipKey)
                ->first();
            if (
                $existing !== null
                && $existing->status === 'ready'
                && $existing->script_hash === $scriptHash
                && $this->voiceMatches($existing, $voiceTag)
                && $this->fileExists($existing)
            ) {
                return $this->payload($existing, $script, cached: true);
            }

            $generated = $this->generateAudio($script, $familyId);
            if ($generated === null) {
                PoetryAudioCache::query()->updateOrCreate(
                    ['poem_id' => $poem->id, 'clip_key' => $clipKey],
                    [
                        'provider' => 'none',
                        'voice' => $voiceTag['voice'],
                        'model' => $voiceTag['model'],
                        'script_hash' => $scriptHash,
                        'script' => $script,
                        'disk' => (string) config('poetry.audio.disk', 'public'),
                        'path' => '',
                        'mime' => 'audio/mpeg',
                        'bytes' => 0,
                        'status' => 'failed',
                        'error' => 'tts_unavailable',
                    ],
                );

                return [
                    'clip' => $clipKey,
                    'url' => null,
                    'mime' => 'audio/mpeg',
                    'provider' => null,
                    'cached' => false,
                    'status' => 'failed',
                    'script' => $script,
                ];
            }

            $disk = (string) config('poetry.audio.disk', 'public');
            $dir = trim((string) config('poetry.audio.directory', 'poetry-audio'), '/');
            $path = $dir.'/'.$poem->id.'/'.$clipKey.'.mp3';
            Storage::disk($disk)->put($path, $generated['binary']);

            $row = PoetryAudioCache::query()->updateOrCreate(
                ['poem_id' => $poem->id, 'clip_key' => $clipKey],
                [
                    'provider' => $generated['provider'],
                    'voice' => $generated['voice'],
                    'model' => $generated['model'],
                    'script_hash' => $scriptHash,
                    'script' => $script,
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $generated['mime'],
                    'bytes' => strlen($generated['binary']),
                    'status' => 'ready',
                    'error' => null,
                ],
            );

            Log::info('poetry.audio.ready', [
                'poem_id' => $poem->id,
                'clip' => $clipKey,
                'provider' => $generated['provider'],
                'bytes' => strlen($generated['binary']),
            ]);

            return $this->payload($row, $script, cached: false);
        } finally {
            $lock->release();
        }
    }

    /**
     * Prefetch selected clips (default: full poem). Lines/story generate on first play and then stick.
     *
     * @param  list<string>  $clips
     * @return list<array{clip: string, status: string, url: ?string, cached: bool}>
     */
    public function warm(int $poemId, int $familyId, array $clips = ['full']): array
    {
        $this->catalog->findOrFail($poemId);
        if ($clips === []) {
            $clips = ['full'];
        }
        $clips = array_values(array_unique(array_slice($clips, 0, 12)));

        $out = [];
        foreach ($clips as $clip) {
            $row = $this->ensureClip($poemId, (string) $clip, $familyId);
            $out[] = [
                'clip' => $row['clip'],
                'status' => $row['status'],
                'url' => $row['url'],
                'cached' => $row['cached'],
            ];
        }

        return $out;
    }

    public function scriptFor(PoetryPoem $poem, string $clipKey, int $familyId): string
    {
        if ($clipKey === 'full') {
            return $this->fullExpressiveScript($poem);
        }
        if ($clipKey === 'story') {
            return trim($this->ai->storyFor($poem, $familyId));
        }
        if (preg_match('/^line-(\d+)$/', $clipKey, $m)) {
            $lines = $poem->lines();
            $i = (int) $m[1];

            return trim((string) ($lines[$i] ?? ''));
        }
        if (preg_match('/^meaning-(\d+)$/', $clipKey, $m)) {
            $meanings = $this->ai->lineMeaningsFor($poem, $familyId);
            $i = (int) $m[1];

            return trim((string) ($meanings[$i] ?? ''));
        }

        return '';
    }

    /**
     * Natural full-poem reading: title + era + author, then couplets with breathing pauses.
     * Punctuation is tuned for expressive TTS (not a flat join of lines).
     */
    public function fullExpressiveScript(PoetryPoem $poem): string
    {
        $title = trim((string) $poem->title);
        $dynasty = trim((string) ($poem->dynasty ?? ''));
        $author = trim((string) ($poem->author ?? ''));
        $lines = $poem->lines();

        $intro = [];
        if ($title !== '') {
            $intro[] = "《{$title}》";
        }
        if ($dynasty !== '') {
            $intro[] = preg_match('/[朝代]$/u', $dynasty) ? $dynasty : "{$dynasty}朝";
        }
        if ($author !== '') {
            $intro[] = $author;
        }

        $couplets = [];
        for ($i = 0; $i < count($lines); $i += 2) {
            $a = rtrim($lines[$i], "，。！？、；：");
            $b = isset($lines[$i + 1]) ? rtrim($lines[$i + 1], "，。！？、；：") : '';
            if ($b !== '') {
                // Slight pause between lines in a couplet, longer pause after the couplet.
                $couplets[] = "{$a}，{$b}。";
            } else {
                $couplets[] = "{$a}。";
            }
        }

        $parts = [];
        if ($intro !== []) {
            $parts[] = implode('，', $intro).'。';
        }
        if ($couplets !== []) {
            $parts[] = implode('……', $couplets);
        }

        return trim(implode("\n", $parts));
    }

    private function normalizeClipKey(string $clip, PoetryPoem $poem): string
    {
        $clip = trim($clip);
        if ($clip === 'full' || $clip === 'story') {
            return $clip;
        }
        if (preg_match('/^line-(\d+)$/', $clip, $m)) {
            $i = (int) $m[1];
            if ($i >= 0 && $i < count($poem->lines())) {
                return 'line-'.$i;
            }
        }
        if (preg_match('/^meaning-(\d+)$/', $clip, $m)) {
            $i = (int) $m[1];
            if ($i >= 0 && $i < count($poem->lines())) {
                return 'meaning-'.$i;
            }
        }

        return 'full';
    }

    private function scriptHash(string $script): string
    {
        $ver = (string) config('poetry.audio.script_version', 'v1');

        return hash('sha256', $ver.'|'.$script);
    }

    /** @return array{voice: string, model: string, prefer_eleven: bool} */
    private function voiceTag(int $familyId): array
    {
        $cfg = $this->familyAi->resolve($familyId);
        $preferEleven = (bool) config('poetry.audio.prefer_elevenlabs', true)
            && trim((string) ($cfg['tts_elevenlabs_api_key'] ?? '')) !== '';

        return [
            'voice' => (string) config('poetry.audio.voice_zh', config('services.elevenlabs.voice_zh')),
            'model' => (string) config('poetry.audio.model', 'eleven_multilingual_v2'),
            'prefer_eleven' => $preferEleven,
        ];
    }

    private function voiceMatches(PoetryAudioCache $row, array $voiceTag): bool
    {
        if ($row->provider === 'elevenlabs') {
            return $row->voice === $voiceTag['voice'] && $row->model === $voiceTag['model'];
        }

        // Fallback providers: keep as long as script matches; voice tag only gates ElevenLabs.
        return true;
    }

    private function fileExists(PoetryAudioCache $row): bool
    {
        if ($row->path === '' || $row->path === null) {
            return false;
        }

        return Storage::disk((string) $row->disk)->exists((string) $row->path);
    }

    /**
     * @return array{binary: string, mime: string, provider: string, voice: string, model: string}|null
     */
    private function generateAudio(string $script, int $familyId): ?array
    {
        $script = trim($script);
        if ($script === '') {
            return null;
        }

        $cfg = $this->familyAi->resolve($familyId);
        $elevenKey = trim((string) ($cfg['tts_elevenlabs_api_key'] ?? ''));
        $preferEleven = (bool) config('poetry.audio.prefer_elevenlabs', true);
        $providerSetting = (string) ($cfg['tts_provider'] ?? 'auto');

        $tryEleven = $elevenKey !== '' && (
            $providerSetting === 'elevenlabs'
            || ($preferEleven && in_array($providerSetting, ['auto', 'elevenlabs'], true))
        );

        if ($tryEleven) {
            $eleven = $this->synthesizeElevenLabs($script, $elevenKey);
            if ($eleven !== null) {
                return $eleven;
            }
        }

        $fallback = $this->tts->synthesize($script, $familyId, 'zh-CN');
        if ($fallback === null || empty($fallback['audio_base64'])) {
            return null;
        }
        $binary = base64_decode((string) $fallback['audio_base64'], true);
        if ($binary === false || strlen($binary) < 500) {
            return null;
        }

        return [
            'binary' => $binary,
            'mime' => (string) ($fallback['mime'] ?? 'audio/mpeg'),
            'provider' => (string) ($fallback['provider'] ?? 'tts'),
            'voice' => 'family-tts',
            'model' => (string) ($fallback['provider'] ?? 'tts'),
        ];
    }

    /**
     * @return array{binary: string, mime: string, provider: string, voice: string, model: string}|null
     */
    private function synthesizeElevenLabs(string $text, string $apiKey): ?array
    {
        $voice = (string) config('poetry.audio.voice_zh', config('services.elevenlabs.voice_zh', 'Xb7hH8MSUJpSbSDYk0k2'));
        $model = (string) config('poetry.audio.model', 'eleven_multilingual_v2');
        $timeout = max(15, (int) config('poetry.audio.timeout', 45));
        $stability = (float) config('poetry.audio.stability', 0.32);
        $similarity = (float) config('poetry.audio.similarity_boost', 0.8);
        $style = (float) config('poetry.audio.style', 0.55);
        $url = 'https://api.elevenlabs.io/v1/text-to-speech/'.$voice.'?output_format=mp3_44100_128';

        try {
            $response = Http::withHeaders([
                'xi-api-key' => $apiKey,
                'Accept' => 'audio/mpeg',
            ])->timeout($timeout)->post($url, [
                'text' => $text,
                'model_id' => $model,
                'language_code' => 'zh',
                'voice_settings' => [
                    'stability' => max(0.0, min(1.0, $stability)),
                    'similarity_boost' => max(0.0, min(1.0, $similarity)),
                    'style' => max(0.0, min(1.0, $style)),
                    'use_speaker_boost' => true,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('poetry.audio.elevenlabs_http', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('poetry.audio.elevenlabs_status', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return null;
        }

        $binary = $response->body();
        if (strlen($binary) < 2000) {
            Log::warning('poetry.audio.elevenlabs_too_small', ['bytes' => strlen($binary)]);

            return null;
        }

        return [
            'binary' => $binary,
            'mime' => 'audio/mpeg',
            'provider' => 'elevenlabs',
            'voice' => $voice,
            'model' => $model,
        ];
    }

    /**
     * @return array{clip: string, url: ?string, mime: string, provider: ?string, cached: bool, status: string, script: string}
     */
    private function payload(PoetryAudioCache $row, string $script, bool $cached): array
    {
        $url = null;
        if ($row->status === 'ready' && $row->path) {
            $url = Storage::disk((string) $row->disk)->url((string) $row->path);
        }

        return [
            'clip' => (string) $row->clip_key,
            'url' => $url,
            'mime' => (string) ($row->mime ?: 'audio/mpeg'),
            'provider' => $row->provider ?: null,
            'cached' => $cached,
            'status' => (string) $row->status,
            'script' => $script,
        ];
    }
}
