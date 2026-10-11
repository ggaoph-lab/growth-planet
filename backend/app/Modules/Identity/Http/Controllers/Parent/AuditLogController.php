<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController
{
    public function index(Request $request, CurrentContext $context): JsonResponse
    {
        $data = $request->validate([
            'action' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z_.]+$/'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $familyId = $context->familyId();
        $tz = $context->family()->timezone;

        $page = AuditLog::query()
            ->where('family_id', $familyId)
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        $actorNames = FamilyMember::query()
            ->where('family_id', $familyId)
            ->whereIn('user_id', $page->getCollection()->pluck('actor_user_id')->filter()->unique())
            ->pluck('display_name', 'user_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_user_id ? ($actorNames[$log->actor_user_id] ?? null) : null,
                'actor_role' => $log->actor_role,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'created_at' => Clock::iso($log->created_at, $tz),
            ]),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
