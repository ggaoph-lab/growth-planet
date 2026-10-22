<?php

namespace App\Modules\Shop\Http\Controllers\Parent;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Enums\RewardKind;
use App\Modules\Shop\Models\Wish;
use App\Modules\Shop\Services\WishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WishController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly WishService $wishes,
    ) {}

    public function index(): JsonResponse
    {
        $wishes = Wish::query()
            ->with(['child:id,nickname', 'reward'])
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $wishes]);
    }

    public function reject(int $id): JsonResponse
    {
        $wish = $this->find($id);

        return response()->json(['data' => $this->wishes->reject($wish)]);
    }

    public function convert(int $id, Request $request): JsonResponse
    {
        $wish = $this->find($id);
        $data = $request->validate([
            'coin_price' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'point_price' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'kind' => ['nullable', Rule::enum(RewardKind::class)],
        ]);

        $kind = isset($data['kind']) ? RewardKind::from($data['kind']) : RewardKind::Physical;
        $price = $kind->pricedInPoints()
            ? ($data['point_price'] ?? $data['coin_price'] ?? $wish->coin_price)
            : ($data['coin_price'] ?? $wish->coin_price);
        if ($price === null) {
            throw new ApiException(
                'WISH_PRICE_REQUIRED',
                $kind->pricedInPoints()
                    ? BiMsg::of('请先填写积分价格', 'Enter a point price first')
                    : BiMsg::of('请先填写成长币价格', 'Enter a coin price first'),
            );
        }

        $converted = $this->wishes->convert($wish, (int) $price, $kind);

        return response()->json(['data' => $converted]);
    }

    private function find(int $id): Wish
    {
        return Wish::query()->where('family_id', $this->context->familyId())->findOrFail($id);
    }
}
