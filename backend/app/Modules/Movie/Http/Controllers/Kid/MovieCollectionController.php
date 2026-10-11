<?php

namespace App\Modules\Movie\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Movie\Services\MovieCollectionService;
use Illuminate\Http\JsonResponse;

class MovieCollectionController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly MovieCollectionService $collections,
    ) {}

    public function show(int $id): JsonResponse
    {
        $payload = $this->collections->kidCollectionDetail($this->context->child(), $id);

        return response()->json(['data' => $payload]);
    }
}
