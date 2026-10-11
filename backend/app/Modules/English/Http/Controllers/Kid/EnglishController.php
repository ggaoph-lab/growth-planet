<?php

namespace App\Modules\English\Http\Controllers\Kid;

use App\Modules\English\Models\EnglishShow;
use App\Modules\English\Models\EnglishWatchLog;
use App\Modules\English\Services\EnglishLogService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnglishController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly EnglishLogService $logs,
    ) {}

    public function indexShows(): JsonResponse
    {
        $shows = EnglishShow::query()
            ->where('family_id', $this->context->child()->family_id)
            ->where('active', true)
            ->orderBy('sort')
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $shows]);
    }

    public function indexLogs(): JsonResponse
    {
        $logs = EnglishWatchLog::query()
            ->where('child_id', $this->context->child()->id)
            ->with('show')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function storeLog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'show_id' => ['required', 'integer'],
            'watch_date' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'self_chosen' => ['nullable', 'boolean'],
            'liking' => ['nullable', 'in:like,ok,dislike'],
            'words_learned' => ['nullable', 'array', 'max:5'],
            'words_learned.*' => ['string', 'max:32'],
        ]);

        if ($data['minutes'] % 5 !== 0) {
            return response()->json(['error' => ['code' => 'VALIDATION_FAILED', 'message' => '时长需为 5 分钟的倍数']], 422);
        }

        $log = $this->logs->createLog($this->context->child(), $data);

        return response()->json(['data' => $log], 201);
    }

    public function destroyLog(int $id): JsonResponse
    {
        $this->logs->cancelLog($this->context->child(), $id);

        return response()->json(['data' => ['ok' => true]]);
    }
}
