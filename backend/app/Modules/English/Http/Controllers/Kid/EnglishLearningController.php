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

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->learning->plan($this->context->child())]);
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
