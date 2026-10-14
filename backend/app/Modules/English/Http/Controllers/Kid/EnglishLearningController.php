<?php

namespace App\Modules\English\Http\Controllers\Kid;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\English\Services\EnglishSpeakService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnglishLearningController
{
    public function __construct(
        private CurrentContext $context,
        private EnglishLearningService $learning,
        private EnglishSpeakService $speak,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $prefer = $request->query('prefer');

        return response()->json([
            'data' => $this->learning->plan($this->context->child(), is_string($prefer) ? $prefer : null),
        ]);
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:64'], 'answer' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->learning->answer($this->context->child(), $data['key'], $data['answer'])]);
    }

    public function addWord(Request $request): JsonResponse
    {
        $data = $request->validate(['word' => ['required', 'string', 'max:40']]);

        return response()->json([
            'data' => $this->learning->addLearningWord($this->context->child(), $data['word']),
        ], 201);
    }

    public function fairyTale(Request $request): JsonResponse
    {
        $date = $request->query('date');
        if (is_string($date) && $date !== '') {
            return response()->json(['data' => $this->learning->fairyTaleOnDate($this->context->child(), $date)]);
        }

        return response()->json(['data' => $this->learning->fairyTale($this->context->child())]);
    }

    public function fairyTaleHeard(): JsonResponse
    {
        return response()->json(['data' => $this->learning->fairyTaleHeard($this->context->child())]);
    }

    public function fairyTaleWord(Request $request): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:40'],
            'date' => ['nullable', 'string', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->learning->collectFairyTaleWord(
                $this->context->child(),
                $data['word'],
                $data['date'] ?? null,
            ),
        ]);
    }

    public function records(): JsonResponse
    {
        return response()->json(['data' => $this->learning->records($this->context->child())]);
    }

    public function speakPrompt(): JsonResponse
    {
        return response()->json(['data' => $this->speak->prompt($this->context->child())]);
    }

    public function speakSubmit(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Kilobytes. MediaRecorder blobs are small; keep headroom for Safari mp4.
            'audio' => ['required', 'file', 'max:2048'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:15000'],
        ]);

        return response()->json([
            'data' => $this->speak->submit(
                $this->context->child(),
                $data['audio'],
                isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            ),
        ], 201);
    }
}
