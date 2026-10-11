<?php

namespace App\Modules\Shop\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RewardController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $rewards = Reward::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rewards]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'kind' => ['required', Rule::enum(RewardKind::class)],
            'coin_price' => ['required', 'integer', 'min:1'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'requires_approval' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(RewardStatus::class)],
        ]);

        $reward = Reward::query()->create([
            'family_id' => $this->context->familyId(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'kind' => $data['kind'],
            'coin_price' => $data['coin_price'],
            'stock' => $data['stock'] ?? null,
            'requires_approval' => $data['requires_approval'] ?? true,
            'status' => $data['status'] ?? RewardStatus::Draft,
            'version' => 1,
        ]);

        return response()->json(['data' => $reward], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $reward = Reward::query()->where('family_id', $this->context->familyId())->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'coin_price' => ['sometimes', 'integer', 'min:1'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'requires_approval' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(RewardStatus::class)],
        ]);
        $reward->fill($data);
        $reward->version++;
        $reward->save();

        return response()->json(['data' => $reward]);
    }
}
