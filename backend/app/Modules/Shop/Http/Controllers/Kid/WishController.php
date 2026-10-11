<?php

namespace App\Modules\Shop\Http\Controllers\Kid;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Shop\Models\Wish;
use App\Modules\Shop\Services\WishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly WishService $wishes,
    ) {}

    public function index(): JsonResponse
    {
        $child = $this->context->child();
        $wishes = Wish::query()
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $wishes]);
    }

    public function store(Request $request): JsonResponse
    {
        $child = $this->context->child();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'coin_price' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $name = trim($data['name']);
        if ($name === '') {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('请写下愿望名称', 'Write a wish name'));
        }
        $description = isset($data['description']) ? trim($data['description']) : '';
        $wish = $this->wishes->create(
            $child,
            $name,
            $description === '' ? null : $description,
            $data['coin_price'] ?? null,
        );

        return response()->json(['data' => $wish], 201);
    }
}
