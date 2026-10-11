<?php

namespace App\Modules\ScreenTime\Http\Controllers\Parent;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\ScreenTime\Models\ScreenTimeAdjustment;
use App\Modules\ScreenTime\Models\ScreenTimePolicy;
use App\Modules\ScreenTime\Services\ScreenTimePolicySeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScreenTimeController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Clock $clock,
        private readonly ScreenTimePolicySeeder $policySeeder,
    ) {}

    public function policies(Request $request): JsonResponse
    {
        $childId = (int) $request->query('child_id');
        $child = $this->context->findChild($childId);
        $this->policySeeder->seedForChild($child);

        $policies = ScreenTimePolicy::query()->where('child_id', $child->id)->get();

        return response()->json(['data' => $policies]);
    }

    public function updatePolicies(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'policies' => ['required', 'array'],
            'policies.*.category' => ['required', 'string'],
            'policies.*.period' => ['required', 'string'],
            'policies.*.minutes' => ['required', 'integer', 'min:0'],
        ]);

        $child = $this->context->findChild($data['child_id']);
        $today = $this->clock->today($this->context->family()->timezone)->toDateString();

        foreach ($data['policies'] as $row) {
            ScreenTimePolicy::query()->updateOrCreate(
                [
                    'child_id' => $child->id,
                    'category' => $row['category'],
                    'period' => $row['period'],
                ],
                [
                    'family_id' => $child->family_id,
                    'minutes' => $row['minutes'],
                    'warn_before_minutes' => 15,
                    'carry_over' => false,
                    'effective_from' => $today,
                ],
            );
        }

        return response()->json(['data' => ScreenTimePolicy::query()->where('child_id', $child->id)->get()]);
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'category' => ['required', 'string'],
            'period_start' => ['required', 'date'],
            'kind' => ['required', 'in:extra_allowance,correction'],
            'seconds' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $child = $this->context->findChild($data['child_id']);

        $adj = ScreenTimeAdjustment::query()->create([
            'child_id' => $child->id,
            'category' => $data['category'],
            'period_start' => $data['period_start'],
            'kind' => $data['kind'],
            'seconds' => $data['seconds'],
            'reason' => $data['reason'] ?? null,
            'created_by' => $this->context->user()->id,
            'created_at' => $this->clock->now(),
        ]);

        return response()->json(['data' => $adj], 201);
    }
}
