<?php

namespace App\Modules\Task\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Task\Services\HomeworkImportService;
use App\Modules\Task\Services\HomeworkParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HomeworkController
{
    private const SUBJECTS = ['语文', '数学', '英语', '科学', '道法', '体育', '美术', '音乐', '其他'];

    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly HomeworkParser $parser,
        private readonly HomeworkImportService $importer,
    ) {}

    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:8000'],
            'task_date' => ['nullable', 'date_format:Y-m-d'],
            'provider' => ['nullable', 'string', Rule::in(['auto', 'gemini', 'qwen', 'rules'])],
        ]);

        $today = $data['task_date']
            ?? $this->clock->today($this->context->family()->timezone)->toDateString();

        $preview = $this->parser->parse(
            $data['text'],
            $today,
            $data['provider'] ?? null,
            $this->context->familyId(),
        );

        return response()->json(['data' => $preview]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'subject' => ['required', 'string', Rule::in(self::SUBJECTS)],
            'task_date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:128'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.title' => ['required', 'string', 'max:128'],
            'items.*.detail' => ['nullable', 'string', 'max:500'],
            'items.*.suggested_coins' => ['nullable', 'integer', 'min:0', 'max:50'],
            'items.*.suggested_xp' => ['nullable', 'integer', 'min:0', 'max:100'],
            'xp_reward' => ['required', 'integer', 'min:0', 'max:100'],
            'coin_reward' => ['required', 'integer', 'min:0', 'max:50'],
            'point_reward' => ['nullable', 'integer', 'min:0', 'max:100'],
            'requires_approval' => ['nullable', 'boolean'],
            'requires_self_check' => ['nullable', 'boolean'],
            'with_parent' => ['nullable', 'boolean'],
            'source_text' => ['nullable', 'string', 'max:8000'],
        ]);

        $result = $this->importer->confirm(
            $this->context->family(),
            $this->context->user(),
            $data,
        );

        return response()->json([
            'data' => [
                'task' => $result['task'],
                'instances' => $result['instances']->values(),
            ],
        ], 201);
    }
}
