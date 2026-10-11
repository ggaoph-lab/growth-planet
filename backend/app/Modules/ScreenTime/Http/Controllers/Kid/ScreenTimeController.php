<?php

namespace App\Modules\ScreenTime\Http\Controllers\Kid;

use App\Modules\Identity\Support\CurrentContext;
use App\Modules\ScreenTime\Services\ScreenTimePolicySeeder;
use App\Modules\ScreenTime\Services\ScreenTimeService;
use Illuminate\Http\JsonResponse;

class ScreenTimeController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly ScreenTimeService $screenTime,
        private readonly ScreenTimePolicySeeder $policySeeder,
    ) {}

    public function show(): JsonResponse
    {
        $child = $this->context->child();
        $this->policySeeder->seedForChild($child);

        return response()->json(['data' => $this->screenTime->summaryForChild($child)]);
    }
}
