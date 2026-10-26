<?php

namespace App\Modules\Ielts\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\CurrentContext;
use App\Modules\Ielts\Services\IeltsRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Parent-editable reward caps for 雅思星球 (Word Atlas). */
class IeltsSettingsController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly Settings $settings,
        private readonly IeltsRewardService $rewards,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->rewards->options($this->context->familyId())]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'rewards_enabled' => ['sometimes', 'boolean'],
            'points_daily_cap' => ['sometimes', 'integer', 'between:0,200'],
            'coins_daily_cap' => ['sometimes', 'integer', 'between:0,200'],
            'mastered_points' => ['sometimes', 'integer', 'between:0,20'],
            'mastered_daily_cap' => ['sometimes', 'integer', 'between:0,200'],
        ]);

        $familyId = $this->context->familyId();
        foreach ($data as $key => $value) {
            $this->settings->set('ielts.'.$key, $value, $familyId, $this->context->user()->id);
        }
        $audit->log('ielts.settings_updated', $familyId, $this->context->family(), $data);

        return $this->show();
    }
}
