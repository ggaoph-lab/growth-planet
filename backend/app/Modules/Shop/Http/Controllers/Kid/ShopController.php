<?php

namespace App\Modules\Shop\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Enums\RewardStatus;
use App\Modules\Shop\Models\Reward;
use App\Modules\Shop\Models\RewardRedemption;
use App\Modules\Shop\Services\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly RedemptionService $redemptions,
    ) {}

    public function index(): JsonResponse
    {
        $rewards = Reward::query()
            ->where('family_id', $this->context->familyId())
            ->where('status', RewardStatus::OnShelf)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rewards]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['reward_id' => ['required', 'integer']]);
        $key = $request->header('Idempotency-Key');
        $child = $this->context->child();
        $reward = Reward::query()
            ->where('family_id', $child->family_id)
            ->findOrFail($data['reward_id']);

        $redemption = $this->redemptions->request(
            $reward,
            $child,
            $this->context->family(),
            $key ?? 'missing-key',
        );

        return response()->json(['data' => $redemption], 201);
    }

    public function cancel(string $ulid): JsonResponse
    {
        $child = $this->context->child();
        $redemption = RewardRedemption::query()
            ->where('family_id', $child->family_id)
            ->where('ulid', $ulid)
            ->firstOrFail();

        return response()->json(['data' => $this->redemptions->cancel($redemption, $child)]);
    }
}
