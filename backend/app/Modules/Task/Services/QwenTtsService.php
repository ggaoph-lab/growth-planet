<?php

namespace App\Modules\Task\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kid read-aloud TTS.
 *
 * Priority:
 * 1) Gemini 2.5 Flash Preview TTS when a Gemini key is configured (free tier)
 * 2) Microsoft Edge neural (free, low latency)
 * 3) Qwen-TTS / CosyVoice when a DashScope key is configured
 * 4) Google / Youdao / Baidu public proxies
 *
 * Always returns base64 audio so the kid browser never depends on third-party CORS/URL expiry.
 */
class QwenTtsService
{
    public function __construct(
        private readonly FamilyAiConfig $ai,
        private readonly EdgeTtsClient $edge,
    ) {}

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    public function synthesize(string $text, int $familyId, string $lang = 'zh-CN'): ?array
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return null;
        }

        $lang = $this->normalizeLang($lang);
        $cfg = $this->ai->resolve($familyId);
        $geminiKey = trim($cfg['gemini_api_key']);
        $qwenKey = trim($cfg['qwen_api_key']);
        $preferGemini = $geminiKey !== '' && (bool) config('services.tts.prefer_gemini', true);
        $voiceTag = $preferGemini
            ? 'gemini:'.$this->geminiVoiceFor($lang)
            : $this->edgeVoiceFor($lang);
        $cacheKey = $this->cacheKey($text, $voiceTag);
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock($cacheKey.':lock', 25);
        try {
            $lock->block(12);
        } catch (LockTimeoutException) {
            return $this->readCache($cacheKey);
        }

        try {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $result = null;
            if ($preferGemini) {
                $result = $this->synthesizeGemini($text, $geminiKey, $lang);
            }

            $result ??= $this->synthesizeEdge($text, $this->edgeVoiceFor($lang));
            $english = str_starts_with($lang, 'en');

            if ($result === null && $qwenKey !== '') {
                $result = $this->synthesizeQwenTts($text, $qwenKey, $lang);
                if ($result === null && ! $english) {
                    $result = $this->synthesizeCosyVoice($text, $qwenKey);
                }
            }

            $result ??= $this->synthesizeGoogleTranslate($text, $lang);
            if ($result === null && ! $english) {
                $result = $this->synthesizeYoudao($text)
                    ?? $this->synthesizeBaiduTranslate($text);
            }

            if ($result === null) {
                return null;
            }

            $this->storeCache($cacheKey, $result);
            Log::info('tts.ready', ['provider' => $result['provider']]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Fill the read-aloud cache for the tasks on screen.
     *
     * @param  list<string>  $texts
     * @return list<array{text: string, audio_base64: string|null, mime: string|null, provider: string|null}>
     */
    public function warm(array $texts, string $lang = 'zh-CN', ?int $familyId = null): array
    {
        $lang = $this->normalizeLang($lang);
        $cfg = $this->ai->resolve($familyId);
        $geminiKey = trim($cfg['gemini_api_key']);
        $preferGemini = $geminiKey !== '' && (bool) config('services.tts.prefer_gemini', true);
        $voiceTag = $preferGemini
            ? 'gemini:'.$this->geminiVoiceFor($lang)
            : $this->edgeVoiceFor($lang);
        $edgeVoice = $this->edgeVoiceFor($lang);
        $ordered = [];
        $aliases = [];
        foreach ($texts as $raw) {
            $raw = trim((string) $raw);
            $text = $this->normalizeText($raw);
            if ($text === '') {
                continue;
            }
            $aliases[$text][] = $raw;
            if (isset($ordered[$text])) {
                continue;
            }
            $ordered[$text] = [
                'text' => $raw,
                'audio_base64' => null,
                'mime' => null,
                'provider' => null,
            ];
            if (count($ordered) >= 8) {
                break;
            }
        }

        $pending = [];
        $locks = [];
        foreach (array_keys($ordered) as $text) {
            $cacheKey = $this->cacheKey($text, $voiceTag);
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                $ordered[$text] = array_merge($ordered[$text], $cached);

                continue;
            }

            $lock = Cache::lock($cacheKey.':lock', 25);
            try {
                $lock->block(8);
            } catch (LockTimeoutException) {
                $cached = $this->readCache($cacheKey);
                if ($cached !== null) {
                    $ordered[$text] = array_merge($ordered[$text], $cached);
                }

                continue;
            }

            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                $lock->release();
                $ordered[$text] = array_merge($ordered[$text], $cached);

                continue;
            }

            $pending[] = $text;
            $locks[$text] = $lock;
        }

        if ($pending !== [] && $preferGemini) {
            $stillPending = [];
            foreach ($pending as $text) {
                $audio = $this->synthesizeGemini($text, $geminiKey, $lang);
                if ($audio !== null) {
                    $this->storeCache($this->cacheKey($text, $voiceTag), $audio);
                    $ordered[$text] = array_merge($ordered[$text], $audio);
                    Log::info('tts.ready', ['provider' => $audio['provider']]);
                    if (isset($locks[$text])) {
                        $locks[$text]->release();
                        unset($locks[$text]);
                    }
                } else {
                    $stillPending[] = $text;
                }
            }
            $pending = $stillPending;
        }

        if ($pending !== []) {
            try {
                $this->edge->synthesizeMany($pending, $edgeVoice, function (int $index, ?array $audio) use (&$ordered, $pending, &$locks, $voiceTag): void {
                    $text = $pending[$index];
                    if ($audio !== null) {
                        $this->storeCache($this->cacheKey($text, $voiceTag), $audio);
                        $ordered[$text] = array_merge($ordered[$text], $audio);
                        Log::info('tts.ready', ['provider' => $audio['provider']]);
                    }
                    if (isset($locks[$text])) {
                        $locks[$text]->release();
                        unset($locks[$text]);
                    }
                });
            } finally {
                foreach ($locks as $lock) {
                    $lock->release();
                }
            }
        } else {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        $out = [];
        foreach ($ordered as $text => $row) {
            foreach (array_unique($aliases[$text] ?? [$row['text']]) as $raw) {
                $out[] = array_merge($row, ['text' => $raw]);
            }
        }

        return $out;
    }

    private function cacheKey(string $text, string $voice): string
    {
        return 'tts:v8:'.$voice.':'.hash('sha256', $text);
    }

    private function normalizeLang(string $lang): string
    {
        return str_starts_with(strtolower(str_replace('_', '-', trim($lang))), 'en') ? 'en-US' : 'zh-CN';
    }

    private function edgeVoiceFor(string $lang): string
    {
        if (str_starts_with($lang, 'en')) {
            return (string) config('services.tts.edge_voice_en', 'en-US-JennyNeural');
        }

        return (string) config('services.tts.edge_voice', 'zh-CN-XiaoxiaoNeural');
    }

    private function geminiVoiceFor(string $lang): string
    {
        if (str_starts_with($lang, 'en')) {
            return (string) config('services.gemini.tts_voice_en', 'Kore');
        }

        return (string) config('services.gemini.tts_voice', 'Kore');
    }

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function readCache(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);
        if (! is_array($cached) || ! isset($cached['audio_base64'], $cached['mime'], $cached['provider'])) {
            return null;
        }
        if (($cached['provider'] ?? '') === 'piper') {
            Cache::forget($cacheKey);

            return null;
        }

        return $cached;
    }

    /**
     * @param  array{audio_base64: string, mime: string, provider: string}  $result
     */
    private function storeCache(string $cacheKey, array $result): void
    {
        if (! in_array($result['provider'], ['gemini_tts', 'edge_tts', 'qwen', 'cosyvoice'], true)) {
            return;
        }

        Cache::put($cacheKey, $result, now()->addMinutes(30));
    }

    public function qwenConfigured(int $familyId): bool
    {
        return trim($this->ai->resolve($familyId)['qwen_api_key']) !== '';
    }

    public function geminiConfigured(int $familyId): bool
    {
        return trim($this->ai->resolve($familyId)['gemini_api_key']) !== '';
    }

    /**
     * Gemini 2.5 Flash Preview TTS via generateContent (AUDIO modality).
     *
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeGemini(string $text, string $apiKey, string $lang): ?array
    {
        $model = (string) config('services.gemini.tts_model', 'gemini-2.5-flash-preview-tts');
        $voice = $this->geminiVoiceFor($lang);
        $base = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(8, (int) config('services.gemini.tts_timeout', 30));
        $url = $base.'/models/'.$model.':generateContent';

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $text]],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => [
                            'voiceName' => $voice,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('tts.gemini_connection_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('tts.gemini_failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);

            return null;
        }

        $parts = data_get($response->json(), 'candidates.0.content.parts');
        if (! is_array($parts)) {
            Log::warning('tts.gemini_empty', ['json' => $response->json()]);

            return null;
        }

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (! is_array($inline)) {
                continue;
            }
            $data = $inline['data'] ?? null;
            if (! is_string($data) || $data === '') {
                continue;
            }
            $binary = base64_decode($data, true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? '');
            [$binary, $mime] = $this->normalizeGeminiAudio($binary, $mime);

            return [
                'audio_base64' => base64_encode($binary),
                'mime' => $mime,
                'provider' => 'gemini_tts',
            ];
        }

        Log::warning('tts.gemini_empty', ['json' => $response->json()]);

        return null;
    }

    /**
     * Gemini returns headerless PCM (L16) by default; browsers need a WAV wrapper.
     *
     * @return array{0: string, 1: string}
     */
    private function normalizeGeminiAudio(string $binary, string $mime): array
    {
        $mimeLower = strtolower(trim($mime));
        if ($mimeLower === '' || str_contains($mimeLower, 'l16') || str_contains($mimeLower, 'pcm')) {
            $rate = $this->sampleRateFromMime($mimeLower) ?? 24000;

            return [$this->pcmToWav($binary, $rate), 'audio/wav'];
        }

        if (str_contains($mimeLower, 'wav')) {
            return [$binary, 'audio/wav'];
        }
        if (str_contains($mimeLower, 'mpeg') || str_contains($mimeLower, 'mp3')) {
            return [$binary, 'audio/mpeg'];
        }
        if (str_contains($mimeLower, 'ogg')) {
            return [$binary, 'audio/ogg'];
        }

        // Unknown payload that already looks like a container — keep bytes, label as wav for <audio>.
        if (str_starts_with($binary, 'RIFF') || str_starts_with($binary, 'ID3') || $this->looksLikeMp3($binary)) {
            return [$binary, str_starts_with($binary, 'RIFF') ? 'audio/wav' : 'audio/mpeg'];
        }

        return [$this->pcmToWav($binary, 24000), 'audio/wav'];
    }

    private function sampleRateFromMime(string $mime): ?int
    {
        if (preg_match('/rate\s*=\s*(\d+)/i', $mime, $matches) === 1) {
            $rate = (int) $matches[1];

            return $rate > 0 ? $rate : null;
        }

        return null;
    }

    private function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $byteRate = (int) ($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int) ($channels * ($bitsPerSample / 8));
        $dataSize = strlen($pcm);

        return 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize)
            .$pcm;
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // Softer phrasing for kids: title。description → short spoken sentence.
        $text = str_replace(["\r\n", "\n", "\r"], '，', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[；;]+/u', '，', $text) ?? $text;

        return mb_substr($text, 0, 280);
    }

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeCosyVoice(string $text, string $apiKey): ?array
    {
        $model = (string) config('services.qwen.tts_cosy_model', 'cosyvoice-v2');
        $voice = (string) config('services.qwen.tts_cosy_voice', 'longxiaochun_v2');
        $base = rtrim((string) config('services.qwen.tts_base_url', 'https://dashscope.aliyuncs.com/api/v1'), '/');
        $timeout = max(8, (int) config('services.qwen.tts_timeout', 25));

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->withHeaders([
                    'X-DashScope-TTS-Audio-Format' => 'mp3',
                ])
                ->acceptJson()
                ->post($base.'/services/audio/tts/SpeechSynthesizer', [
                    'model' => $model,
                    'input' => [
                        'text' => $text,
                        'voice' => $voice,
                    ],
                    'parameters' => [
                        'voice' => $voice,
                        'format' => 'mp3',
                        'sample_rate' => 22050,
                        'rate' => 0.92,
                        'pitch' => 1.0,
                        'volume' => 55,
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('tts.cosyvoice_connection_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('tts.cosyvoice_failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);

            return null;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = $response->body();

        // Binary audio response.
        if (str_contains($contentType, 'audio') || $this->looksLikeMp3($body)) {
            return [
                'audio_base64' => base64_encode($body),
                'mime' => 'audio/mpeg',
                'provider' => 'cosyvoice',
            ];
        }

        // JSON with URL.
        $url = data_get($response->json(), 'output.audio.url')
            ?? data_get($response->json(), 'output.audio');
        if (is_string($url) && str_starts_with($url, 'http')) {
            return $this->downloadToBase64($url, 'cosyvoice');
        }

        Log::warning('tts.cosyvoice_unexpected', ['content_type' => $contentType]);

        return null;
    }

    /**
     * Microsoft Edge neural TTS via open protocol (no key).
     *
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeEdge(string $text, string $voice): ?array
    {
        return $this->edge->synthesize($text, $voice);
    }

    /**
     * Youdao dict voice — warmer female Chinese than OS defaults; no key.
     *
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeYoudao(string $text): ?array
    {
        $chunks = $this->chunkText($text, 80);
        $parts = [];
        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                        'Referer' => 'https://dict.youdao.com/',
                    ])
                    ->get('https://dict.youdao.com/dictvoice', [
                        'audio' => $chunk,
                        'le' => 'zh',
                        'type' => 2,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('tts.youdao_connection_failed', ['error' => $e->getMessage()]);

                return null;
            }

            $body = $response->body();
            if (! $response->successful() || strlen($body) < 64) {
                Log::warning('tts.youdao_failed', ['status' => $response->status()]);

                return null;
            }
            $parts[] = $body;
        }

        if ($parts === []) {
            return null;
        }

        return [
            'audio_base64' => base64_encode(implode('', $parts)),
            'mime' => 'audio/mpeg',
            'provider' => 'youdao_tts',
        ];
    }

    /**
     * Google Translate TTS — soft neural-ish voice; no key.
     *
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeGoogleTranslate(string $text, string $lang = 'zh-CN'): ?array
    {
        $chunks = $this->chunkText($text, 100);
        $parts = [];
        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                    ])
                    ->get('https://translate.google.com/translate_tts', [
                        'ie' => 'UTF-8',
                        'client' => 'tw-ob',
                        'tl' => str_starts_with($lang, 'en') ? 'en' : 'zh-CN',
                        'q' => $chunk,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('tts.google_connection_failed', ['error' => $e->getMessage()]);

                return null;
            }

            $body = $response->body();
            if (! $response->successful() || strlen($body) < 64) {
                Log::warning('tts.google_failed', ['status' => $response->status()]);

                return null;
            }
            $parts[] = $body;
        }

        if ($parts === []) {
            return null;
        }

        return [
            'audio_base64' => base64_encode(implode('', $parts)),
            'mime' => 'audio/mpeg',
            'provider' => 'google_tts',
        ];
    }

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeQwenTts(string $text, string $apiKey, string $lang = 'zh-CN'): ?array
    {
        $voice = (string) config('services.qwen.tts_voice', 'Cherry');
        $model = (string) config('services.qwen.tts_model', 'qwen3-tts-flash');
        $primary = rtrim((string) config('services.qwen.tts_base_url', 'https://dashscope.aliyuncs.com/api/v1'), '/');
        $timeout = max(8, (int) config('services.qwen.tts_timeout', 25));

        // Beijing key vs Singapore/intl key — try configured base, then the other region.
        $bases = array_values(array_unique([
            $primary,
            'https://dashscope.aliyuncs.com/api/v1',
            'https://dashscope-intl.aliyuncs.com/api/v1',
        ]));

        foreach ($bases as $base) {
            try {
                $response = Http::timeout($timeout)
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->post($base.'/services/aigc/multimodal-generation/generation', [
                        'model' => $model,
                        'input' => [
                            'text' => $text,
                            'voice' => $voice,
                            'language_type' => str_starts_with($lang, 'en') ? 'English' : 'Chinese',
                        ],
                        // Prefer mp3 for reliable browser playback (Safari is picky with huge wav data URLs).
                        'parameters' => [
                            'format' => 'mp3',
                        ],
                    ]);
            } catch (\Throwable $e) {
                Log::warning('tts.qwen_connection_failed', ['base' => $base, 'error' => $e->getMessage()]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('tts.qwen_failed', [
                    'base' => $base,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 400),
                ]);

                continue;
            }

            $url = data_get($response->json(), 'output.audio.url');
            $data = data_get($response->json(), 'output.audio.data');

            if (is_string($url) && str_starts_with($url, 'http')) {
                return $this->downloadToBase64($url, 'qwen');
            }

            if (is_string($data) && strlen($data) > 64) {
                if (str_starts_with($data, 'http')) {
                    return $this->downloadToBase64($data, 'qwen');
                }

                return [
                    'audio_base64' => $data,
                    'mime' => 'audio/mpeg',
                    'provider' => 'qwen',
                ];
            }

            Log::warning('tts.qwen_empty_url', ['base' => $base, 'json' => $response->json()]);
        }

        return null;
    }

    /**
     * No-key fallback — warmer female Chinese voice than OS defaults; reachable in CN.
     *
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function synthesizeBaiduTranslate(string $text): ?array
    {
        $chunks = $this->chunkText($text, 120);
        $parts = [];
        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                        'Referer' => 'https://fanyi.baidu.com/',
                    ])
                    ->get('https://fanyi.baidu.com/gettts', [
                        'lan' => 'zh',
                        'text' => $chunk,
                        'spd' => 4,
                        'source' => 'web',
                    ]);
            } catch (\Throwable $e) {
                Log::warning('tts.baidu_connection_failed', ['error' => $e->getMessage()]);

                return null;
            }

            $body = $response->body();
            if (! $response->successful() || strlen($body) < 64) {
                Log::warning('tts.baidu_failed', ['status' => $response->status()]);

                return null;
            }
            $parts[] = $body;
        }

        if ($parts === []) {
            return null;
        }

        return [
            'audio_base64' => base64_encode(implode('', $parts)),
            'mime' => 'audio/mp3',
            'provider' => 'baidu_tts',
        ];
    }

    /**
     * @return array{audio_base64: string, mime: string, provider: string}|null
     */
    private function downloadToBase64(string $url, string $provider): ?array
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'GrowthPlanet/1.0'])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('tts.download_failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || $response->body() === '') {
            Log::warning('tts.download_empty', ['provider' => $provider, 'status' => $response->status()]);

            return null;
        }

        $body = $response->body();
        $headerMime = strtolower((string) $response->header('Content-Type'));
        $mime = 'audio/mpeg';
        if (str_starts_with($body, 'RIFF') || str_contains($headerMime, 'wav')) {
            $mime = 'audio/wav';
        } elseif (str_contains($headerMime, 'mpeg') || str_contains($headerMime, 'mp3')) {
            $mime = 'audio/mpeg';
        } elseif (str_starts_with($body, 'ID3') || (strlen($body) > 1 && ord($body[0]) === 0xFF && (ord($body[1]) & 0xE0) === 0xE0)) {
            $mime = 'audio/mpeg';
        }

        return [
            'audio_base64' => base64_encode($body),
            'mime' => $mime,
            'provider' => $provider,
        ];
    }

    /**
     * @return list<string>
     */
    private function chunkText(string $text, int $maxChars): array
    {
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        $buf = '';
        $parts = preg_split('/(?<=[。！？，、,.!?])/u', $text) ?: [$text];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (mb_strlen($buf.$part) > $maxChars && $buf !== '') {
                $chunks[] = $buf;
                $buf = $part;
            } else {
                $buf .= $part;
            }
        }
        if ($buf !== '') {
            $chunks[] = $buf;
        }

        // Hard-split any leftover oversize piece.
        $out = [];
        foreach ($chunks as $chunk) {
            while (mb_strlen($chunk) > $maxChars) {
                $out[] = mb_substr($chunk, 0, $maxChars);
                $chunk = mb_substr($chunk, $maxChars);
            }
            if ($chunk !== '') {
                $out[] = $chunk;
            }
        }

        return $out;
    }

    private function looksLikeMp3(string $body): bool
    {
        if (strlen($body) < 4) {
            return false;
        }

        // ID3 tag or MPEG frame sync.
        return str_starts_with($body, 'ID3') || (ord($body[0]) === 0xFF && (ord($body[1]) & 0xE0) === 0xE0);
    }
}
