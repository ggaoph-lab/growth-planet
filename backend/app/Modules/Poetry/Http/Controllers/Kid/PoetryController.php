<?php

namespace App\Modules\Poetry\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Poetry\Services\PoetryAudioService;
use App\Modules\Poetry\Services\PoetryPracticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'data' => $this->audio->readyClip(
                $id,
                (string) ($data['clip'] ?? 'full'),
                (int) $child->family_id,
            ),
        ]);
    }

    /** Stream a disk-cached poem clip (ElevenLabs/library MP3). */
    public function audioFile(Request $request, int $id): BinaryFileResponse
    {
        $data = $request->validate([
            'clip' => ['sometimes', 'string', 'max:64'],
        ]);
        // Ensure the kid session is valid; clips are not family-secret but stay behind auth.
        $child = $this->context->child();

        $file = $this->audio->absolutePathForReadyClip($id, (string) ($data['clip'] ?? 'full'), (int) $child->family_id);
        abort_if($file === null, 404);

        $response = response()->file($file['path'], [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age=604800',
            'Accept-Ranges' => 'bytes',
        ]);
        $response->setPrivate();

        return $response;
    }

    public function warmAudio(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'clips' => ['sometimes', 'array', 'max:24'],
            'clips.*' => ['string', 'max:64'],
        ]);
        $child = $this->context->child();
        $clips = array_values(array_filter(array_map('strval', $data['clips'] ?? ['full'])));
        // Allow full + lines + meanings for one poem in a single warm (grade-1 ≈ 9 clips).
        $clips = array_values(array_unique(array_slice($clips !== [] ? $clips : ['full'], 0, 24)));

        return response()->json([
            'data' => [
                'items' => array_map(
                    fn (string $clip) => $this->audio->readyClip($id, $clip, (int) $child->family_id),
                    $clips,
                ),
            ],
        ]);
    }
}
