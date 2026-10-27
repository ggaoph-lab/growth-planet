<?php

namespace App\Modules\Poetry\Services;

use App\Modules\Poetry\Image\BuiltinPoemIllustrations;
use App\Modules\Poetry\Image\ImageProvider;
use App\Modules\Poetry\Image\ImageProviderManager;
use App\Modules\Poetry\Models\PoetryImageCache;
use App\Modules\Poetry\Models\PoetryPoem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        if ($cached !== null && ! $this->shouldRegenerateMock($cached, $poem)) {
            return [
                'url' => $cached->image_url,
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

            if ($row !== null && $row->status === 'ready' && $row->image_url && ! $this->shouldRegenerateMock($row, $poem)) {
                return [
                    'url' => $row->image_url,
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

                return $this->persistReady(
                    $row,
                    $poem,
                    $name,
                    $result['model'] ?? null,
                    $result['url'],
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

        if (str_starts_with($model, 'mock-placeholder') || str_starts_with($model, 'mock-scene-svg')) {
            return true;
        }
        if (str_contains($url, 'image/svg') || str_contains($url, 'image/png;base64')) {
            return true;
        }

        return false;
    }
}
