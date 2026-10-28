<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Core\Settings\Settings;
use App\Modules\Poetry\Image\BuiltinPoemIllustrations;
use App\Modules\Poetry\Image\ImageProvider;
use App\Modules\Poetry\Image\ImageProviderManager;
use App\Modules\Poetry\Models\PoetryImageCache;
use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Jobs\PreparePoemAssets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generate once, cache forever (per poem + provider). Subsequent opens read DB only.
 *
 * Preference for offline/mock path:
 * 1) curated textbook-style JPG for builtin grade-1 poems
 * 2) procedural SVG scene illustrator
 * Wanx (when configured) still wins for families that enable it.
 */
class PoemImageService
{
    /** Return a cached image immediately; missing assets are prepared by the queue. */
    public function cachedOrSchedule(PoetryPoem $poem, int $familyId): array
    {
        $this->hydrateWanxKeyFromFamily($familyId);
        $provider = $this->providers->resolve($familyId);
        $name = $provider->isConfigured() ? $provider->name() : 'mock';
        foreach (array_unique([$name, 'mock']) as $candidate) {
            $row = PoetryImageCache::query()->where('poem_id', $poem->id)
                ->where('provider', $candidate)->where('status', 'ready')
                ->whereNotNull('image_url')->first();
            if ($row !== null && $this->cachedFileExists($row) && ! $this->shouldRegenerateMock($row, $poem)) {
                if ($candidate !== $name) {
                    $this->schedulePreparation((int) $poem->id, $familyId);
                }
                return ['url' => $this->publicCachedUrl($row), 'provider' => $candidate, 'status' => 'ready', 'cached' => true];
            }
        }

        $this->schedulePreparation((int) $poem->id, $familyId);
        if (app()->runningUnitTests()) {
            return $this->ensureImage($poem, $familyId);
        }
        return ['url' => null, 'provider' => $name, 'status' => 'pending', 'cached' => false];
    }

    public function schedulePreparation(int $poemId, int $familyId): void
    {
        if (app()->runningUnitTests()) {
            (new PreparePoemAssets($poemId, $familyId))->handle(
                $this,
                app(PoetryAiCoach::class),
                app(PoetryAudioService::class),
                $this->providers,
            );
            return;
        }
        if (! Cache::add("poetry-prepare:{$poemId}:{$familyId}", true, 300)) {
            return;
        }
        PreparePoemAssets::dispatch($poemId, $familyId);
    }

    /** Convert older stored PNG/JPEG illustrations without calling the image provider again. */
    public function optimizeExistingImages(PoetryPoem $poem): void
    {
        $rows = PoetryImageCache::query()->where('poem_id', $poem->id)->where('status', 'ready')->get();
        foreach ($rows as $row) {
            $url = (string) $row->image_url;
            if (! str_starts_with($url, '/storage/') || ! preg_match('/\.(png|jpe?g)$/i', $url)) {
                continue;
            }
            $path = substr($url, strlen('/storage/'));
            if (! Storage::disk('public')->exists($path)) {
                continue;
            }
            try {
                $webpUrl = $this->storeWebp($poem, $row->provider, Storage::disk('public')->get($path));
                $row->image_url = $webpUrl;
                $row->save();
            } catch (\Throwable $e) {
                Log::warning('poetry.image_optimize_failed', ['poem_id' => $poem->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function __construct(
        private readonly ImageProviderManager $providers,
        private readonly PoetryAiCoach $ai,
        private readonly BuiltinPoemIllustrations $illustrations,
    ) {}

    /**
     * @return array{url: ?string, provider: string, status: string, cached: bool}
     */
    public function ensureImage(PoetryPoem $poem, int $familyId): array
    {
        $this->hydrateWanxKeyFromFamily($familyId);
        $provider = $this->providers->resolve($familyId);
        if (! $provider->isConfigured()) {
            $provider = $this->providers->get('mock');
        }

        $result = $this->generateWithProvider($poem, $familyId, $provider);
        if ($result['url'] !== null || $provider->name() === 'mock') {
            return $result;
        }

        return $this->generateWithProvider($poem, $familyId, $this->providers->get('mock'));
    }

    /**
     * @return array{url: ?string, provider: string, status: string, cached: bool}
     */
    private function generateWithProvider(PoetryPoem $poem, int $familyId, ImageProvider $provider): array
    {
        $name = $provider->name();

        $cached = PoetryImageCache::query()
            ->where('poem_id', $poem->id)
            ->where('provider', $name)
            ->where('status', 'ready')
            ->whereNotNull('image_url')
            ->first();

        if ($cached !== null && $this->cachedFileExists($cached) && ! $this->shouldRegenerateMock($cached, $poem)) {
            return [
                'url' => $this->publicCachedUrl($cached),
                'provider' => $name,
                'status' => 'ready',
                'cached' => true,
            ];
        }

        return DB::transaction(function () use ($poem, $provider, $name, $familyId, $cached) {
            $row = PoetryImageCache::query()
                ->where('poem_id', $poem->id)
                ->where('provider', $name)
                ->lockForUpdate()
                ->first();

            if ($row !== null && $row->status === 'ready' && $row->image_url && $this->cachedFileExists($row) && ! $this->shouldRegenerateMock($row, $poem)) {
                return [
                    'url' => $this->publicCachedUrl($row),
                    'provider' => $name,
                    'status' => 'ready',
                    'cached' => true,
                ];
            }

            $row = $row ?? $cached;

            // Prefer curated textbook illustrations when using the offline provider.
            if ($name === 'mock') {
                $builtinUrl = $this->illustrations->publicUrl($poem);
                if ($builtinUrl !== null) {
                    return $this->persistReady($row, $poem, $name, 'builtin-illustration', $builtinUrl, 'builtin textbook illustration');
                }
            }

            $prompt = $this->ai->imagePromptFor($poem, $familyId);
            $seed = "古诗《{$poem->title}》".implode('', $poem->lines());
            $style = (string) config('poetry.image.style_suffix', '');
            $fullPrompt = trim($seed.'。'.$prompt.($style !== '' ? '，'.$style : ''));

            try {
                $result = $provider->generate($fullPrompt);
                $url = $this->persistRemoteImageLocally($poem, $name, $result['url']);

                return $this->persistReady(
                    $row,
                    $poem,
                    $name,
                    $result['model'] ?? null,
                    $url,
                    $fullPrompt,
                );
            } catch (\Throwable $e) {
                Log::warning('poetry.image_generate_failed', [
                    'poem_id' => $poem->id,
                    'provider' => $name,
                    'error' => $e->getMessage(),
                ]);
                $fail = [
                    'poem_id' => $poem->id,
                    'provider' => $name,
                    'model' => null,
                    'prompt' => $fullPrompt,
                    'image_url' => null,
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ];
                if ($row === null) {
                    PoetryImageCache::query()->create($fail);
                } else {
                    $row->fill($fail)->save();
                }

                return [
                    'url' => null,
                    'provider' => $name,
                    'status' => 'failed',
                    'cached' => false,
                ];
            }
        });
    }

    /**
     * @return array{url: string, provider: string, status: string, cached: bool}
     */
    private function persistReady(
        ?PoetryImageCache $row,
        PoetryPoem $poem,
        string $provider,
        ?string $model,
        string $url,
        string $prompt,
    ): array {
        $payload = [
            'poem_id' => $poem->id,
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'image_url' => $url,
            'status' => 'ready',
            'error' => null,
        ];
        if ($row === null) {
            PoetryImageCache::query()->create($payload);
        } else {
            $row->fill($payload)->save();
        }

        return [
            'url' => $url,
            'provider' => $provider,
            'status' => 'ready',
            'cached' => false,
        ];
    }

    private function shouldRegenerateMock(PoetryImageCache $row, PoetryPoem $poem): bool
    {
        if ($row->provider !== 'mock') {
            return false;
        }

        $model = (string) ($row->model ?? '');
        $url = (string) ($row->image_url ?? '');
        $hasBuiltin = $this->illustrations->publicUrl($poem) !== null;

        // Already on curated textbook art.
        if (str_starts_with($model, 'builtin-illustration') && str_starts_with($url, '/poetry-art/')) {
            return false;
        }

        // Upgrade legacy placeholder / abstract SVG once builtin art exists.
        if ($hasBuiltin) {
            return true;
        }

        // One-shot upgrade from the oldest abstract PNG placeholders.
        if (str_starts_with($model, 'mock-placeholder') || str_starts_with($url, 'data:')) {
            return true;
        }

        return false;
    }

    private function cachedFileExists(PoetryImageCache $row): bool
    {
        $url = (string) $row->image_url;
        if (str_starts_with($url, '/storage/')) {
            return Storage::disk('public')->exists(substr($url, strlen('/storage/')));
        }
        if (str_starts_with($url, '/poetry-art/')) {
            return true; // Bundled with the nginx frontend image, not the PHP image.
        }
        return false;
    }

    private function publicCachedUrl(PoetryImageCache $row): string
    {
        $url = (string) $row->image_url;
        if (str_starts_with($url, '/storage/')) {
            $path = substr($url, strlen('/storage/'));
            return $url.'?v='.Storage::disk('public')->lastModified($path);
        }
        return $url;
    }

    /** Prefer env DASHSCOPE_API_KEY; fall back to the family Qwen chat key. */
    private function hydrateWanxKeyFromFamily(int $familyId): void
    {
        if (trim((string) config('poetry.image.wanx.api_key', '')) !== '') {
            return;
        }
        $familyKey = trim((string) app(Settings::class)->get('ai.qwen_api_key', $familyId));
        if ($familyKey !== '') {
            config(['poetry.image.wanx.api_key' => $familyKey]);
        }
    }

    /**
     * Wanx returns short-lived OSS HTTPS URLs blocked by our CSP (img-src self/data/blob).
     * Mirror into public storage so the kid UI can load the illustration.
     */
    private function persistRemoteImageLocally(PoetryPoem $poem, string $provider, string $url): string
    {
        if (str_starts_with($url, 'data:image/svg+xml;base64,')) {
            $bytes = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('Invalid generated SVG');
            }
            $path = "poetry-art/generated/{$poem->id}-{$provider}.svg";
            if (! Storage::disk('public')->put($path, $bytes)) {
                throw new \RuntimeException('Generated SVG storage write failed');
            }
            return '/storage/'.$path;
        }
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            if (str_starts_with($url, '/storage/') || str_starts_with($url, '/poetry-art/')) {
                return $url;
            }
            throw new \RuntimeException('Image provider did not return a storable image');
        }

        try {
            $response = Http::timeout(45)->get($url);
            if (! $response->successful()) {
                Log::warning('poetry.image_mirror_failed', [
                    'poem_id' => $poem->id,
                    'provider' => $provider,
                    'status' => $response->status(),
                ]);

                throw new \RuntimeException('Image mirror returned HTTP '.$response->status());
            }
            $bytes = $response->body();
            if ($bytes === '') {
                throw new \RuntimeException('Image mirror returned empty body');
            }
            $mime = strtolower((string) $response->header('Content-Type'));
            if (! str_starts_with($mime, 'image/')) {
                throw new \RuntimeException('Image mirror returned non-image content');
            }
            return $this->storeWebp($poem, $provider, $bytes);
        } catch (\Throwable $e) {
            Log::warning('poetry.image_mirror_failed', [
                'poem_id' => $poem->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function storeWebp(PoetryPoem $poem, string $provider, string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \RuntimeException('Image bytes could not be decoded');
        }
        try {
            ob_start();
            $ok = imagewebp($image, null, 82);
            $webp = ob_get_clean();
            if (! $ok || ! is_string($webp) || $webp === '') {
                throw new \RuntimeException('WebP encoding failed');
            }
            $path = "poetry-art/generated/{$poem->id}-{$provider}.webp";
            if (! Storage::disk('public')->put($path, $webp)) {
                throw new \RuntimeException('WebP storage write failed');
            }
            return '/storage/'.$path;
        } finally {
            imagedestroy($image);
        }
    }
}
