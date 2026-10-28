<?php

namespace App\Modules\Poetry\Jobs;

use App\Modules\Poetry\Services\PoetryAudioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PreparePoemAudioClip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(public int $poemId, public int $familyId, public string $clip) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PoetryAudioService $audio): void
    {
        $result = $audio->ensureClip($this->poemId, $this->clip, $this->familyId);
        if ($result['status'] !== 'ready' || $result['url'] === null) {
            throw new \RuntimeException('Poem audio clip is not ready: '.$this->clip);
        }
    }
}
