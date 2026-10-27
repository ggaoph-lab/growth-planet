<?php

namespace App\Modules\Poetry\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Services\PoetryPracticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoetryController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly PoetryPracticeService $practice,
        private readonly PoetryAudioService $audio,
    ) {}

    public function home(): JsonResponse
    {
        return response()->json(['data' => $this->practice->home($this->context->child())]);
    }

    public function required(): JsonResponse
    {
        return response()->json(['data' => $this->practice->requiredList($this->context->child())]);
    }

    public function recommend(): JsonResponse
    {
        return response()->json(['data' => $this->practice->recommendList($this->context->child())]);
    }

    public function challenge(): JsonResponse
    {
        return response()->json(['data' => $this->practice->challengeList($this->context->child())]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->practice->showPoem($this->context->child(), $id)]);
    }

    public function readAloud(int $id): JsonResponse
    {
        return response()->json(['data' => $this->practice->markReadAloud($this->context->child(), $id)]);
    }

    public function transcribe(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:3072'],
            'duration_ms' => ['required', 'integer', 'between:300,60000'],
        ]);
        $file = $data['audio'];
        $mime = (string) $file->getMimeType();
        abort_unless(in_array($mime, ['audio/webm', 'video/webm', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg'], true), 422);
        $audio = file_get_contents($file->getRealPath());
        abort_if($audio === false || strlen($audio) < 32, 422);

        return response()->json([
            'data' => $this->practice->transcribe(
                $this->context->child(),
                $id,
                $audio,
                $mime,
            ),
        ]);
    }

    public function recite(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:2000'],
            'mode' => ['sometimes', 'string', 'in:recite,review,challenge,read_aloud'],
            // legacy alias
            'is_review' => ['sometimes', 'boolean'],
        ]);

        $mode = (string) ($data['mode'] ?? 'recite');
        if (($data['is_review'] ?? false) === true) {
            $mode = 'review';
        }

        return response()->json([
            'data' => $this->practice->recite(
                $this->context->child(),
                $id,
                (string) $data['transcript'],
                $mode,
            ),
        ]);
    }

    public function audio(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'clip' => ['sometimes', 'string', 'max:64'],
        ]);
        $child = $this->context->child();

        return response()->json([
            'data' => $this->audio->ensureClip(
                $id,
                (string) ($data['clip'] ?? 'full'),
                (int) $child->family_id,
            ),
        ]);
    }

    public function warmAudio(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'clips' => ['sometimes', 'array', 'max:12'],
            'clips.*' => ['string', 'max:64'],
        ]);
        $child = $this->context->child();
        $clips = array_values(array_filter(array_map('strval', $data['clips'] ?? ['full'])));

        return response()->json([
            'data' => [
                'items' => $this->audio->warm(
                    $id,
                    (int) $child->family_id,
                    $clips !== [] ? $clips : ['full'],
                ),
            ],
        ]);
    }
}
