<?php

namespace App\Modules\Exploration\Http\Controllers\Kid;

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

    public function index(): JsonResponse
    {
        $child = $this->context->child();
        $projects = ExplorationProject::query()
            ->with(['events' => fn ($q) => $q->orderByDesc('event_date')->limit(5)])
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->where('status', ExplorationProjectStatus::Active)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $projects]);
    }

    public function store(Request $request): JsonResponse
    {
        $child = $this->context->child();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:128'],
            'category' => ['required', Rule::enum(ExplorationCategory::class)],
        ]);

        $project = $this->exploration->createProject(
            $child->family_id,
            $child,
            $data['title'],
            ExplorationCategory::from($data['category']),
        );

        return response()->json(['data' => $project], 201);
    }

    public function storeEvent(int $id, Request $request): JsonResponse
    {
        $child = $this->context->child();
        $project = ExplorationProject::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->findOrFail($id);

        $data = $request->validate([
            'event_date' => ['nullable', 'date'],
            'stage' => ['required', 'integer', 'min:1', 'max:5'],
            'feeling' => ['nullable', 'string', 'max:32'],
            'willing_next' => ['nullable', Rule::enum(WillingNext::class)],
            'child_words' => ['nullable', 'string'],
        ]);

        $event = $this->exploration->recordEvent($project, $this->context->user(), $data);

        return response()->json(['data' => ['event' => $event, 'project' => $project->fresh()]], 201);
    }
}
