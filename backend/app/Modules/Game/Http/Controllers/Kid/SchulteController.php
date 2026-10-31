<?php

namespace App\Modules\Game\Http\Controllers\Kid;

use App\Modules\Game\Services\SchulteScoreService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchulteController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly SchulteScoreService $scores,
    ) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();

        return response()->json([
            'data' => $this->scores->board($child, $this->context->family()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'size' => ['required', 'integer', 'in:3,4,5'],
            'elapsed_ms' => ['required', 'integer', 'min:1', 'max:'.SchulteScoreService::MAX_MS],
            'play_id' => ['required', 'uuid'],
        ]);
        $child = $this->context->child();
        $result = $this->scores->record($child, $this->context->family(), (int) $data['size'], (int) $data['elapsed_ms'], $data['play_id']);

        return response()->json(['data' => $result]);
    }
}
