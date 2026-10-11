<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Http\Resources\ParentChildResource;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController
{
    public function __invoke(Request $request, CurrentContext $context, Clock $clock): JsonResponse
    {
        $familyId = $context->familyId();
        $family = $context->family();

        $children = ChildProfile::query()
            ->with(['family', 'member.user'])
            ->where('family_id', $familyId)
            ->orderBy('id')
            ->get();

        return response()->json(['data' => [
            'family' => ['name' => $family->name, 'timezone' => $family->timezone],
            'today' => $clock->today($family->timezone)->toDateString(),
            'children' => ParentChildResource::collection($children)->resolve($request),
            'active_devices' => TrustedDevice::query()->where('family_id', $familyId)->active()->count(),
            'recent_activity' => AuditLog::query()
                ->where('family_id', $familyId)
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'action', 'actor_role', 'created_at'])
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor_role' => $log->actor_role,
                    'created_at' => Clock::iso($log->created_at, $family->timezone),
                ]),
        ]]);
    }
}
