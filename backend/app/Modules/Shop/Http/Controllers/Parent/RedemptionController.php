<?php

namespace App\Modules\Shop\Http\Controllers\Parent;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Models\RewardRedemption;
use Illuminate\Http\JsonResponse;

class RedemptionController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $items = RewardRedemption::query()
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $items]);
    }
}
