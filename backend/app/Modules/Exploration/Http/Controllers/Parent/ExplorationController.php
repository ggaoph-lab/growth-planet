<?php

namespace App\Modules\Exploration\Http\Controllers\Parent;

use App\Modules\Exploration\Enums\ExplorationCategory;
use App\Modules\Exploration\Enums\ExplorationProjectStatus;
use App\Modules\Exploration\Enums\WillingNext;
use App\Modules\Exploration\Models\ExplorationProject;
use App\Modules\Exploration\Services\ExplorationService;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExplorationController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ExplorationService $exploration,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['child_id' => ['nullable', 'integer']]);
        $query = ExplorationProject::query()
            ->withCount('events')
            ->where('family_id', $this->context->familyId());

        if (isset($data['child_id'])) {
            $child = $this->context->findChild($data['child_id']);
            $query->where('child_id', $child->id);
        }

        return response()->json(['data' => $query->orderByDesc('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:128'],
            'category' => ['required', Rule::enum(ExplorationCategory::class)],
        ]);

        $child = $this->context->findChild($data['child_id']);
        $project = $this->exploration->createProject(
            $this->context->familyId(),
            $child,
            $data['title'],
            ExplorationCategory::from($data['category']),
        );

        return response()->json(['data' => $project], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $project = ExplorationProject::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', Rule::enum(ExplorationProjectStatus::class)],
        ]);
        $project->fill($data);
        $project->save();

        return response()->json(['data' => $project]);
    }

    public function storeEvent(int $id, Request $request): JsonResponse
    {
        $project = ExplorationProject::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'event_date' => ['nullable', 'date'],
            'stage' => ['required', 'integer', 'min:1', 'max:5'],
            'feeling' => ['nullable', 'string', 'max:32'],
            'willing_next' => ['nullable', Rule::enum(WillingNext::class)],
            'child_words' => ['nullable', 'string'],
            'parent_observation' => ['nullable', 'string'],
        ]);

        $event = $this->exploration->recordEvent($project, $this->context->user(), $data);

        return response()->json(['data' => ['event' => $event, 'project' => $project->fresh()]], 201);
    }
}
