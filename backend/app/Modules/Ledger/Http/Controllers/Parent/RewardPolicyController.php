<?php

namespace App\Modules\Ledger\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ledger\Models\RewardPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardPolicyController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $policies = RewardPolicy::query()
            ->where('family_id', $this->context->familyId())
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $policies]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'xp_amount' => ['sometimes', 'integer', 'min:0'],
            'coin_amount' => ['sometimes', 'integer', 'min:0'],
            'point_amount' => ['sometimes', 'integer', 'min:0'],
            'max_per_day' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $policy = RewardPolicy::query()
            ->where('family_id', $this->context->familyId())
            ->where('code', $data['code'])
            ->firstOrFail();

        $policy->fill(collect($data)->except('code')->all());
        $policy->save();

        return response()->json(['data' => $policy]);
    }
}
