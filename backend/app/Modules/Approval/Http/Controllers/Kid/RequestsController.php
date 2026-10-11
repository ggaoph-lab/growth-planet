<?php

namespace App\Modules\Approval\Http\Controllers\Kid;

use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;

class RequestsController
{
    public function __construct(private readonly CurrentContext $context) {}

    public function index(): JsonResponse
    {
        $child = $this->context->child();
        $items = ApprovalRequest::query()
            ->where('child_id', $child->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $items]);
    }
}
