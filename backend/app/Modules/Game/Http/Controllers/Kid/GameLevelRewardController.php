<?php

namespace App\Modules\Game\Http\Controllers\Kid;

use App\Modules\Game\Services\GameLevelRewardService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameLevelRewardController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly GameLevelRewardService $rewards,
    ) {}

    public function show(string $game): JsonResponse
    {
        $child = $this->context->child();

        return response()->json([
            'data' => $this->rewards->board($child, $this->context->family(), $game),
        ]);
    }

    public function store(Request $request, string $game): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', 'string', 'in:'.implode(',', GameLevelRewardService::LEVELS)],
        ]);
        $child = $this->context->child();
        $result = $this->rewards->claim($child, $this->context->family(), $game, $data['level']);

        return response()->json(['data' => $result]);
    }
}
