<?php

namespace App\Modules\English\Http\Controllers\Kid;

use App\Modules\English\Services\EnglishLearningService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnglishLearningController
{
    public function __construct(private CurrentContext $context, private EnglishLearningService $learning) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->learning->plan($this->context->child())]);
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:64'], 'answer' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->learning->answer($this->context->child(), $data['key'], $data['answer'])]);
    }
}
