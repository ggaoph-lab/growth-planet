<?php

namespace App\Modules\Report\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Report\Services\CollectionService;
use Illuminate\Http\JsonResponse;

class CollectionController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly CollectionService $collection,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'stamps' => $this->collection->stampsForChild($this->context->child()),
            ],
        ]);
    }
}
