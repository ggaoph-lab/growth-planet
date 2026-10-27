<?php

namespace App\Modules\Poetry\Http\Controllers\Parent;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Poetry\Models\PoetryAssignment;
use App\Modules\Poetry\Models\PoetryRecitationRecord;
use App\Modules\Poetry\Models\PoetryReviewPlan;
use App\Modules\Poetry\Services\PoetryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoetryController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Settings $settings,
        private readonly PoetryCatalog $catalog,
    ) {}

    public function index(): JsonResponse
    {
        $familyId = $this->context->familyId();
        $this->catalog->ensureBuiltinGrade1();

        $children = ChildProfile::query()
            ->where('family_id', $familyId)
            ->orderBy('id')
            ->get(['id', 'nickname']);

        $assignments = PoetryAssignment::query()
            ->with('poem')
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PoetryAssignment $a) => [
                'id' => $a->id,
                'child_id' => $a->child_id,
                'due_date' => $a->due_date?->toDateString(),
                'poem' => [
                    'id' => $a->poem->id,
                    'title' => $a->poem->title,
                    'author' => $a->poem->author,
                ],
            ]);

        $recent = PoetryRecitationRecord::query()
            ->with('poem')
            ->where('family_id', $familyId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (PoetryRecitationRecord $r) => [
                'id' => $r->id,
                'child_id' => $r->child_id,
                'kind' => $r->kind,
                'passed' => $r->passed,
                'accuracy_percent' => $r->accuracy_percent,
                'feedback' => $r->feedback,
                'points_awarded' => $r->points_awarded,
                'created_at' => $r->created_at?->toIso8601String(),
                'poem' => [
                    'id' => $r->poem->id,
                    'title' => $r->poem->title,
                ],
            ]);

        $plans = PoetryReviewPlan::query()
            ->with('poem')
            ->where('family_id', $familyId)
            ->orderBy('next_review_date')
            ->limit(50)
            ->get()
            ->map(fn (PoetryReviewPlan $p) => [
                'id' => $p->id,
                'child_id' => $p->child_id,
                'status' => $p->status,
                'step_index' => $p->step_index,
                'next_review_date' => $p->next_review_date?->toDateString(),
                'poem' => [
                    'id' => $p->poem->id,
                    'title' => $p->poem->title,
                ],
            ]);

        return response()->json([
            'data' => [
                'settings' => $this->settingsPayload($familyId),
                'children' => $children,
                'assignments' => $assignments,
                'recent_records' => $recent,
                'review_plans' => $plans,
                'grade1' => array_map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'author' => $p->author,
                    'dynasty' => $p->dynasty,
                ], $this->catalog->grade1Poems()),
            ],
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $familyId = $this->context->familyId();
        $data = $request->validate([
            'allow_ai' => ['sometimes', 'boolean'],
            'image_provider' => ['sometimes', 'string', 'in:mock,wanx'],
            'image_model' => ['sometimes', 'string', 'max:64'],
        ]);

        if (array_key_exists('allow_ai', $data)) {
            $this->settings->set('poetry.allow_ai', (bool) $data['allow_ai'], $familyId, $this->context->user()->id);
        }
        if (array_key_exists('image_provider', $data)) {
            $this->settings->set('poetry.image_provider', (string) $data['image_provider'], $familyId, $this->context->user()->id);
        }
        if (array_key_exists('image_model', $data)) {
            $this->settings->set('poetry.image_model', (string) $data['image_model'], $familyId, $this->context->user()->id);
        }

        return response()->json(['data' => $this->settingsPayload($familyId)]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
        ]);
        $poems = $this->catalog->search((string) ($data['q'] ?? ''), 16);

        return response()->json([
            'data' => array_map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'author' => $p->author,
                'dynasty' => $p->dynasty,
                'preview' => $p->lines()[0] ?? '',
                'source' => $p->source,
            ], $poems),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $familyId = $this->context->familyId();
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'poem_id' => ['required', 'integer'],
            'due_date' => ['nullable', 'date'],
        ]);

        $child = ChildProfile::query()
            ->where('family_id', $familyId)
            ->where('id', (int) $data['child_id'])
            ->first();
        if ($child === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('找不到这个孩子', 'Child not found'), 404);
        }

        $poem = $this->catalog->findOrFail((int) $data['poem_id']);

        $assignment = PoetryAssignment::query()->updateOrCreate(
            [
                'child_id' => $child->id,
                'poem_id' => $poem->id,
            ],
            [
                'family_id' => $familyId,
                'assigned_by' => $this->context->user()->id,
                'due_date' => $data['due_date'] ?? null,
                'status' => 'active',
            ],
        );

        return response()->json([
            'data' => [
                'id' => $assignment->id,
                'child_id' => $assignment->child_id,
                'poem_id' => $poem->id,
                'due_date' => $assignment->due_date?->toDateString(),
                'status' => $assignment->status,
            ],
        ]);
    }

    public function cancelAssignment(int $id): JsonResponse
    {
        $assignment = PoetryAssignment::query()
            ->where('family_id', $this->context->familyId())
            ->where('id', $id)
            ->first();
        if ($assignment === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('找不到这个背诵任务', 'Assignment not found'), 404);
        }
        $assignment->status = 'cancelled';
        $assignment->save();

        return response()->json(['data' => ['id' => $assignment->id, 'status' => 'cancelled']]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(int $familyId): array
    {
        return [
            'allow_ai' => (bool) $this->settings->get('poetry.allow_ai', $familyId),
            'image_provider' => (string) $this->settings->get('poetry.image_provider', $familyId),
            'image_model' => (string) $this->settings->get('poetry.image_model', $familyId),
            'review_intervals_days' => config('poetry.review_intervals_days', [1, 2, 4, 7, 15]),
        ];
    }
}
