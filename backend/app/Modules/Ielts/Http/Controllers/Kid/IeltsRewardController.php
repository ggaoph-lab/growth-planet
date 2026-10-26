<?php

namespace App\Modules\Ielts\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ielts\Services\IeltsRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IeltsRewardController
{
    private const WORD_ID = 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public function __construct(
        private readonly CurrentContext $context,
        private readonly IeltsRewardService $rewards,
    ) {}

    public function settle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'graded' => ['required', 'array', 'max:400'],
            'graded.*' => ['required', 'integer', 'between:1,4'],
            'new_ids' => ['present', 'array', 'max:200'],
            'new_ids.*' => ['string', self::WORD_ID],
            'mastered_ids' => ['sometimes', 'array', 'max:100'],
            'mastered_ids.*' => ['string', self::WORD_ID],
        ]);

        $graded = [];
        foreach ($data['graded'] as $wordId => $grade) {
            if (is_string($wordId) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $wordId) === 1) {
                $graded[$wordId] = (int) $grade;
            }
        }

        $result = $this->rewards->settle(
            $this->context->child(),
            $this->context->family(),
            $data['session_id'],
            $graded,
            array_values($data['new_ids']),
            array_values($data['mastered_ids'] ?? []),
        );

        return response()->json(['data' => $result]);
    }
}
