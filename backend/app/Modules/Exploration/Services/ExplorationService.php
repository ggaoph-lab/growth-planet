<?php

namespace App\Modules\Exploration\Services;

use App\Modules\Core\Support\Clock;
use App\Modules\Exploration\Enums\ExplorationCategory;
use App\Modules\Exploration\Enums\ExplorationProjectStatus;
use App\Modules\Exploration\Enums\WillingNext;
use App\Modules\Exploration\Models\ExplorationEvent;
use App\Modules\Exploration\Models\ExplorationProject;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\User;

class ExplorationService
{
    public function __construct(private readonly Clock $clock) {}

    public function createProject(
        int $familyId,
        ChildProfile $child,
        string $title,
        ExplorationCategory $category,
    ): ExplorationProject {
        return ExplorationProject::query()->create([
            'family_id' => $familyId,
            'child_id' => $child->id,
            'title' => $title,
            'category' => $category,
            'current_stage' => 1,
            'status' => ExplorationProjectStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(ExplorationProject $project, User $actor, array $payload): ExplorationEvent
    {
        $stage = (int) $payload['stage'];
        if ($stage > $project->current_stage) {
            $project->current_stage = $stage;
            $project->save();
        }

        return ExplorationEvent::query()->create([
            'project_id' => $project->id,
            'event_date' => $payload['event_date'] ?? $this->clock->now()->toDateString(),
            'stage' => $stage,
            'feeling' => $payload['feeling'] ?? null,
            'willing_next' => isset($payload['willing_next']) ? WillingNext::from($payload['willing_next']) : null,
            'child_words' => $payload['child_words'] ?? null,
            'parent_observation' => $payload['parent_observation'] ?? null,
            'recorded_by' => $actor->id,
        ]);
    }
}
