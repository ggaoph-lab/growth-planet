<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Poetry\Models\PoetryAudioCache;
use App\Modules\Poetry\Jobs\PreparePoemAudioClip;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Task\Services\FamilyAiConfig;
use App\Modules\Task\Services\QwenTtsService;
use App\Modules\Core\Settings\Settings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Poem read-aloud: expressive script → permanent library (seed/runtime) → TTS.
 * Assign / weekly recommend warm full + lines + meanings once; kids stream static files.
 */
class PoetryAudioService
{
    public function __construct(
        private readonly PoetryCatalog $catalog,
        private readonly PoetryAiCoach $ai,
        private readonly FamilyAiConfig $familyAi,
        private readonly QwenTtsService $tts,
        private readonly PoetryAudioLibrary $library,
        private readonly Settings $settings,
        private readonly QwenPoetryTts $qwenPoetry,
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
        $voiceTag = $this->voiceTag($familyId);
        $scriptHash = hash('sha256', $this->scriptHash($script).'|'.json_encode($voiceTag));
        $storeClip = ($voiceTag['provider'] ?? '') === 'qwen-poetry' ? $clipKey.'--family-'.$familyId : $clipKey;

        $fromLibrary = $this->hydrateFromLibrary($poem, $storeClip, $script, $scriptHash, $voiceTag);
        if ($fromLibrary !== null) {
            return $fromLibrary;
        }

        $existing = PoetryAudioCache::query()
            ->where('poem_id', $poem->id)
            ->where('clip_key', $storeClip)
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

        $lockKey = "poetry-audio:{$poem->id}:{$storeClip}";
        $lock = Cache::lock($lockKey, 90);
        try {
            $lock->block(25);
        } catch (LockTimeoutException) {
            $existing = PoetryAudioCache::query()
                ->where('poem_id', $poem->id)
                ->where('clip_key', $storeClip)
                ->first();
            if ($existing && $existing->status === 'ready' && $this->fileExists($existing)) {
                return $this->payload($existing, $script, cached: true);
            }
            $again = $this->hydrateFromLibrary($poem, $storeClip, $script, $scriptHash, $voiceTag, allowStale: true);
            if ($again !== null) {
                return $again;
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
            $fromLibrary = $this->hydrateFromLibrary($poem, $storeClip, $script, $scriptHash, $voiceTag);
            if ($fromLibrary !== null) {
                return $fromLibrary;
            }

            $existing = PoetryAudioCache::query()
                ->where('poem_id', $poem->id)
                ->where('clip_key', $storeClip)
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

            $generated = $this->generateAudio($script, $familyId, $clipKey, $poem);
            if ($generated === null) {
                // Keep a prior Edge/library clip playable rather than failing the kid tap.
                $stale = $this->hydrateFromLibrary($poem, $storeClip, $script, $scriptHash, $voiceTag, allowStale: true);
                if ($stale !== null) {
                    return $stale;
                }
                if ($existing && $existing->status === 'ready' && $this->fileExists($existing)) {
                    return $this->payload($existing, $script, cached: true);
                }

                PoetryAudioCache::query()->updateOrCreate(
                    ['poem_id' => $poem->id, 'clip_key' => $storeClip],
                    [
                        'provider' => 'none',
                        'voice' => $voiceTag['voice'],
                        'model' => $voiceTag['model'],
                        'script_hash' => $scriptHash,
                        'script' => $script,
                        'disk' => 'local',
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

            $absolute = $this->library->put($poem, $storeClip, $generated['binary'], [
                'provider' => $generated['provider'],
                'voice' => $generated['voice'],
                'model' => $generated['model'],
                'script_hash' => $scriptHash,
            ]);
            $relative = $this->runtimeRelativePath($poem, $storeClip);

            $row = PoetryAudioCache::query()->updateOrCreate(
                ['poem_id' => $poem->id, 'clip_key' => $storeClip],
                [
                    'provider' => $generated['provider'],
                    'voice' => $generated['voice'],
                    'model' => $generated['model'],
                    'script_hash' => $scriptHash,
                    'script' => $script,
                    'disk' => 'local',
                    'path' => $relative,
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
                'library' => $absolute,
            ]);

            return $this->payload($row, $script, cached: false);
        } finally {
            $lock->release();
        }
    }

    /**
     * Static practice pack: full + author + each line + each line meaning (not AI story).
     *
     * @return list<string>
     */
    public function libraryClipsFor(PoetryPoem $poem): array
    {
        $clips = ['full', 'author'];
        foreach (array_keys($poem->lines()) as $i) {
            $clips[] = 'line-'.$i;
            $clips[] = 'meaning-'.$i;
        }

        return $clips;
    }

    /**
     * Prefetch permanent library clips (assign / weekly recommend).
     *
     * @return list<array{clip: string, status: string, url: ?string, cached: bool}>
     */
    public function warmLibrary(int $poemId, int $familyId): array
    {
        $poem = $this->catalog->findOrFail($poemId);

        return $this->warm($poemId, $familyId, $this->libraryClipsFor($poem));
    }

    /** Queue each clip separately so slow TTS never occupies an HTTP worker. */
    public function scheduleWarmLibrary(int $poemId, int $familyId, string $source = 'assign'): void
    {
        if (! $this->hasWarmCredentials($familyId)) {
            Log::info('poetry.audio.warm_skipped', [
                'source' => $source,
                'poem_id' => $poemId,
                'family_id' => $familyId,
                'reason' => 'tts_unconfigured',
            ]);

            return;
        }

        $poem = $this->catalog->findOrFail($poemId);
        if (app()->runningUnitTests()) {
            $this->warmLibrary($poemId, $familyId);

            return;
        }
        foreach ($this->libraryClipsFor($poem) as $clip) {
            $this->scheduleClip($poemId, $familyId, $clip);
        }
    }

    private function scheduleClip(int $poemId, int $familyId, string $clip): void
    {
        if (Cache::add("poetry-audio-prepare:{$poemId}:{$familyId}:{$clip}", true, 300)) {
            PreparePoemAudioClip::dispatch($poemId, $familyId, $clip);
        }
    }

    /** Metadata for a server file only. Never synthesize in a kid request. */
    public function readyClip(int $poemId, string $clip, int $familyId): array
    {
        $file = $this->absolutePathForReadyClip($poemId, $clip, $familyId);
        if ($file === null && ! app()->runningUnitTests() && $this->hasWarmCredentials($familyId)) {
            $this->scheduleClip($poemId, $familyId, $clip);
        }
        $row = $file === null ? null : PoetryAudioCache::query()
            ->where('poem_id', $poemId)
            ->whereIn('clip_key', [$file['clip'], $file['clip'].'--family-'.$familyId])
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->first();
        $seedProvider = null;
        if ($file !== null && $row === null) {
            $poem = PoetryPoem::query()->find($poemId);
            $seedProvider = $poem === null ? null : ($this->library->metaFor($poem, $file['clip'])['provider'] ?? null);
        }
        return [
            'clip' => $file['clip'] ?? $clip,
            'url' => $file === null ? null : $this->streamUrl($poemId, $file['clip']).'&v='.filemtime($file['path']),
            'mime' => $file['mime'] ?? 'audio/mpeg',
            'provider' => $file === null ? null : ($row?->provider ?: $seedProvider ?: 'library'),
            'cached' => $file !== null,
            'status' => $file === null ? 'pending' : 'ready',
            'script' => '',
        ];
    }

    /** True when assign/recommend can synthesize into the permanent library (not free-proxy fallback). */
    public function hasWarmCredentials(int $familyId): bool
    {
        $provider = (string) $this->settings->get('poetry.tts_provider', $familyId);
        $cfg = $this->familyAi->resolve($familyId);

        if ($provider === 'qwen') {
            $key = trim((string) $this->settings->get('poetry.tts_api_key', $familyId));
            if ($key === '') {
                $key = trim((string) ($cfg['tts_qwen_api_key'] ?? ''));
            }

            return $key !== '';
        }

        return trim((string) ($cfg['tts_elevenlabs_api_key'] ?? '')) !== '';
    }

    /** @return array{clips:int,bytes:int} */
    public function familyCacheStats(int $familyId): array
    {
        $row = PoetryAudioCache::query()
            ->where('clip_key', 'like', '%--family-'.$familyId)
            ->selectRaw('count(*) as clips, coalesce(sum(bytes), 0) as bytes')
            ->first();

        return [
            'clips' => (int) ($row->clips ?? 0),
            'bytes' => (int) ($row->bytes ?? 0),
        ];
    }

    /** @return array{binary:string,mime:string,provider:string,voice:string,model:string}|null */
    public function previewElevenLabs(int $familyId): ?array
    {
        $key = trim((string) $this->familyAi->resolve($familyId)['tts_elevenlabs_api_key']);
        return $key !== '' ? $this->synthesizeElevenLabs('床前明月光，疑是地上霜。', $key, $familyId) : null;
    }

    /** Clear only this family's generated clips, never shared seed or ElevenLabs audio. */
    public function clearFamilyCache(int $familyId): array
    {
        $rows = PoetryAudioCache::query()->where('clip_key', 'like', '%--family-'.$familyId)->get();
        $bytes = 0;
        foreach ($rows as $row) {
            $path = $this->absoluteFromRow($row);
            if ($path !== null && str_starts_with($path, $this->library->runtimeDirectory().'/')) {
                $bytes += (int) filesize($path);
                @unlink($path);
                @unlink(substr($path, 0, -4).'.json');
            }
            $row->delete();
        }
        return ['clips' => $rows->count(), 'bytes' => $bytes];
    }

    /**
     * Prefetch selected clips (default: full poem).
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
        $clips = array_values(array_unique(array_slice($clips, 0, 48)));

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
        if ($clipKey === 'author') {
            return trim($this->ai->authorIntroFor($poem));
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
            // mb-safe: PHP rtrim() is byte-wise and corrupts UTF-8 Chinese punctuation.
            $a = $this->stripTrailingPunctuation($lines[$i]);
            $b = isset($lines[$i + 1]) ? $this->stripTrailingPunctuation($lines[$i + 1]) : '';
            if ($b !== '') {
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
            $parts[] = implode("\n", $couplets);
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Absolute filesystem path for a ready clip, or null if missing.
     *
     * @return array{path: string, mime: string, bytes: int, clip: string}|null
     */
    public function absolutePathForReadyClip(int $poemId, string $clip, int $familyId): ?array
    {
        $poem = $this->catalog->findOrFail($poemId);
        $clipKey = $this->normalizeClipKey($clip, $poem);
        $voiceTag = $this->voiceTag($familyId);
        $storeClip = ($voiceTag['provider'] ?? '') === 'qwen-poetry' ? $clipKey.'--family-'.$familyId : $clipKey;

        $libraryPath = $this->library->absoluteMp3($poem, $storeClip);
        if ($libraryPath !== null) {
            return [
                'path' => $libraryPath,
                'mime' => 'audio/mpeg',
                'bytes' => (int) (filesize($libraryPath) ?: 0),
                'clip' => $clipKey,
            ];
        }

        $row = PoetryAudioCache::query()
            ->where('poem_id', $poem->id)
            ->where('clip_key', $storeClip)
            ->first();

        if ($row === null || $row->status !== 'ready' || ! $this->fileExists($row)) {
            return null;
        }

        $absolute = $this->absoluteFromRow($row);
        if ($absolute === null) {
            return null;
        }

        return [
            'path' => $absolute,
            'mime' => (string) ($row->mime ?: 'audio/mpeg'),
            'bytes' => (int) ($row->bytes ?: filesize($absolute) ?: 0),
            'clip' => $clipKey,
        ];
    }

    /**
     * @param  array{voice: string, model: string, prefer_eleven: bool}  $voiceTag
     */
    private function hydrateFromLibrary(
        PoetryPoem $poem,
        string $clipKey,
        string $script,
        string $scriptHash,
        array $voiceTag,
        bool $allowStale = false,
    ): ?array {
        // Story text is family AI output — never share via the permanent library.
        if ($clipKey === 'story' || str_contains($clipKey, 'story--family-')) {
            return null;
        }

        $libraryClip = $clipKey;
        $path = $this->library->absoluteMp3($poem, $libraryClip);
        // Family-scoped Qwen keys can still reuse shared seed clips (full/line/meaning).
        if ($path === null && preg_match('/^(.*)--family-\d+$/', $clipKey, $m)) {
            $libraryClip = $m[1];
            $path = $this->library->absoluteMp3($poem, $libraryClip);
        }
        if ($path === null) {
            return null;
        }

        $meta = $this->library->metaFor($poem, $libraryClip) ?? [];
        $provider = (string) ($meta['provider'] ?? 'library');
        if (! $allowStale && ($this->wantsElevenRefresh($provider, $meta['voice'] ?? null, $meta['model'] ?? null, $voiceTag)
            || (($voiceTag['provider'] ?? '') === 'qwen-poetry' && ($meta['script_hash'] ?? null) !== $scriptHash))) {
            // Stale Edge/browser-era file — regenerate with ElevenLabs when a key is configured.
            return null;
        }

        $relative = $this->runtimeRelativePath($poem, $libraryClip);
        $row = PoetryAudioCache::query()->updateOrCreate(
            ['poem_id' => $poem->id, 'clip_key' => $clipKey],
            [
                'provider' => $provider,
                'voice' => $meta['voice'] ?? null,
                'model' => $meta['model'] ?? null,
                'script_hash' => (string) ($meta['script_hash'] ?? $scriptHash),
                'script' => $script,
                'disk' => 'local',
                'path' => $relative,
                'mime' => 'audio/mpeg',
                'bytes' => (int) ($meta['bytes'] ?? filesize($path) ?: 0),
                'status' => 'ready',
                'error' => null,
            ],
        );

        return $this->payload($row, $script, cached: true);
    }

    private function runtimeRelativePath(PoetryPoem $poem, string $clipKey): string
    {
        $key = $this->library->libraryKey($poem);

        return 'poetry-library/audio/'.$key.'/'.$clipKey.'.mp3';
    }

    private function stripTrailingPunctuation(string $line): string
    {
        return preg_replace('/[，。！？、；：,.!?;:]+$/u', '', $line) ?? $line;
    }

    private function normalizeClipKey(string $clip, PoetryPoem $poem): string
    {
        $clip = trim($clip);
        if ($clip === 'full' || $clip === 'story' || $clip === 'author') {
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
        $provider = (string) $this->settings->get('poetry.tts_provider', $familyId);
        if ($provider === 'qwen') {
            return [
                'provider' => 'qwen-poetry',
                'voice' => (string) $this->settings->get('poetry.tts_voice', $familyId),
                'model' => 'qwen-audio-3.1-tts-flash',
                'rate' => (int) $this->settings->get('poetry.tts_rate_percent', $familyId),
                'style' => (string) $this->settings->get('poetry.tts_style', $familyId),
                'prefer_eleven' => false,
            ];
        }
        $preferEleven = (bool) config('poetry.audio.prefer_elevenlabs', true)
            && trim((string) ($cfg['tts_elevenlabs_api_key'] ?? '')) !== '';

        return [
            'provider' => 'elevenlabs',
            'voice' => trim((string) $this->settings->get('poetry.eleven_voice', $familyId))
                ?: (string) config('poetry.audio.voice_zh', config('services.elevenlabs.voice_zh')),
            'model' => (string) config('poetry.audio.model', 'eleven_multilingual_v2'),
            'prefer_eleven' => $preferEleven,
        ];
    }

    private function voiceMatches(PoetryAudioCache $row, array $voiceTag): bool
    {
        if (($voiceTag['provider'] ?? '') === 'qwen-poetry') {
            return $row->provider === 'qwen-poetry' && $row->voice === $voiceTag['voice'] && $row->model === $voiceTag['model'];
        }
        if ($this->wantsElevenRefresh((string) $row->provider, $row->voice, $row->model, $voiceTag)) {
            return false;
        }
        if ($row->provider === 'elevenlabs') {
            return $row->voice === $voiceTag['voice'] && $row->model === $voiceTag['model'];
        }

        return true;
    }

    /**
     * @param  array{voice: string, model: string, prefer_eleven: bool}  $voiceTag
     */
    private function wantsElevenRefresh(?string $provider, ?string $voice, ?string $model, array $voiceTag): bool
    {
        if (($voiceTag['provider'] ?? '') === 'qwen-poetry') {
            return $provider !== 'qwen-poetry' || $voice !== $voiceTag['voice'] || $model !== $voiceTag['model'];
        }
        if (! $voiceTag['prefer_eleven']) {
            return false;
        }
        if ($provider !== 'elevenlabs') {
            return true;
        }

        return $voice !== $voiceTag['voice'] || $model !== $voiceTag['model'];
    }

    private function fileExists(PoetryAudioCache $row): bool
    {
        return $this->absoluteFromRow($row) !== null;
    }

    private function absoluteFromRow(PoetryAudioCache $row): ?string
    {
        if ($row->path === '' || $row->path === null) {
            return null;
        }

        $path = (string) $row->path;
        if ($path[0] === '/' && is_file($path)) {
            return $path;
        }

        $absolute = storage_path('app/'.ltrim($path, '/'));
        if (is_file($absolute) && filesize($absolute) >= 500) {
            return $absolute;
        }

        // Legacy rows may still point at the public disk.
        $public = storage_path('app/public/'.ltrim($path, '/'));
        if (is_file($public) && filesize($public) >= 500) {
            return $public;
        }

        return null;
    }

    /**
     * @return array{binary: string, mime: string, provider: string, voice: string, model: string}|null
     */
    private function generateAudio(string $script, int $familyId, string $clipKey, PoetryPoem $poem): ?array
    {
        $script = trim($script);
        if ($script === '') {
            return null;
        }

        $cfg = $this->familyAi->resolve($familyId);
        if ($this->settings->get('poetry.tts_provider', $familyId) === 'qwen') {
            $key = trim((string) $this->settings->get('poetry.tts_api_key', $familyId));
            if ($key === '') {
                $key = trim((string) $cfg['tts_qwen_api_key']);
            }
            $workspaceId = trim((string) $this->settings->get('poetry.tts_workspace_id', $familyId));
            if ($workspaceId === '') {
                $workspaceId = (string) config('poetry.audio.qwen_workspace_id', '');
            }
            $qwen = $this->qwenPoetry->synthesize(
                $script, $key, $workspaceId,
                (string) $this->settings->get('poetry.tts_region', $familyId),
                (string) $this->settings->get('poetry.tts_voice', $familyId),
                (int) $this->settings->get('poetry.tts_rate_percent', $familyId) / 100,
                (string) $this->settings->get('poetry.tts_style', $familyId),
                $clipKey,
                (string) $poem->title,
                (string) ($poem->author ?? ''),
                implode(' ', $poem->lines()),
            );
            if ($qwen !== null) {
                return $qwen;
            }
            // Do not switch narrator mid-poem. Parents can select ElevenLabs explicitly.
            return null;
        }
        $elevenKey = trim((string) ($cfg['tts_elevenlabs_api_key'] ?? ''));
        $preferEleven = (bool) config('poetry.audio.prefer_elevenlabs', true);
        $providerSetting = (string) ($cfg['tts_provider'] ?? 'auto');

        $tryEleven = $elevenKey !== '' && (
            $this->settings->get('poetry.tts_provider', $familyId) === 'elevenlabs'
            ||
            $providerSetting === 'elevenlabs'
            || ($preferEleven && in_array($providerSetting, ['auto', 'elevenlabs'], true))
        );

        if ($tryEleven) {
            $eleven = $this->synthesizeElevenLabs($script, $elevenKey, $familyId);
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
    private function synthesizeElevenLabs(string $text, string $apiKey, int $familyId): ?array
    {
        $voice = trim((string) $this->settings->get('poetry.eleven_voice', $familyId))
            ?: (string) config('poetry.audio.voice_zh', config('services.elevenlabs.voice_zh', 'Xb7hH8MSUJpSbSDYk0k2'));
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
     * Same-origin API path — public /storage is not mounted on the nginx SPA host.
     */
    private function streamUrl(int $poemId, string $clipKey): string
    {
        return '/api/v1/kid/poetry/poems/'.$poemId.'/audio/file?clip='.rawurlencode($clipKey);
    }

    /**
     * @return array{clip: string, url: ?string, mime: string, provider: ?string, cached: bool, status: string, script: string}
     */
    private function payload(PoetryAudioCache $row, string $script, bool $cached): array
    {
        $publicClip = preg_replace('/--family-\d+$/', '', (string) $row->clip_key);
        $url = null;
        if ($row->status === 'ready' && $row->path) {
            $url = $this->streamUrl((int) $row->poem_id, $publicClip);
        }

        return [
            'clip' => $publicClip,
            'url' => $url,
            'mime' => (string) ($row->mime ?: 'audio/mpeg'),
            'provider' => $row->provider ?: null,
            'cached' => $cached,
            'status' => (string) $row->status,
            'script' => $script,
        ];
    }
}
