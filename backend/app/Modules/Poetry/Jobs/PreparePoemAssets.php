<?php

namespace App\Modules\Poetry\Jobs;

use App\Modules\Poetry\Models\PoetryPoem;
use App\Modules\Poetry\Services\PoemImageService;
use App\Modules\Poetry\Services\PoetryAiCoach;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Image\ImageProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PreparePoemAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $poemId, public int $familyId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PoemImageService $images, PoetryAiCoach $ai, PoetryAudioService $audio, ImageProviderManager $providers): void
    {
        $poem = PoetryPoem::query()->find($this->poemId);
        if ($poem === null) {
            return;
        }
        $ai->upgradeEnrichmentViaAi($poem, $this->familyId);
        $image = $images->ensureImage($poem, $this->familyId);
        $audio->scheduleWarmLibrary($this->poemId, $this->familyId);
        if ($image['url'] === null) {
            throw new \RuntimeException('Poem image generation failed; retry queued');
        }
        $preferred = $providers->resolve($this->familyId);
        if ($preferred->isConfigured() && $image['provider'] !== $preferred->name()) {
            throw new \RuntimeException('Preferred poem image generation failed; retry queued');
        }
    }
}
