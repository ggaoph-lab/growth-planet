<?php

namespace App\Modules\Identity\Http\Controllers\Parent;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, DeviceTokens $tokens): JsonResponse
    {
        $current = $tokens->fromRequest($request);
        $devices = TrustedDevice::query()
            ->with('authorizer.member')
            ->where('family_id', $this->context->familyId())
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrustedDevice $d) => $this->present($d, $current?->id === $d->id));

        return response()->json(['data' => $devices]);
    }

    /** Authorises the browser making this request; the token only ever lives in an HttpOnly cookie. */
    public function store(Request $request, DeviceTokens $tokens): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:64']]);
        $familyId = $this->context->familyId();

        $previous = $tokens->fromRequest($request);
        if ($previous !== null && $previous->family_id === $familyId) {
            $previous->forceFill(['revoked_at' => $this->clock->now()])->save();
        }

        [$device, $token] = $tokens->issue($familyId, $data['name'], $this->context->user());
        $this->audit->log('device.authorized', $familyId, $device, ['name' => $data['name']]);

        return response()
            ->json(['data' => $this->present($device->load('authorizer.member'), true)], 201)
            ->withCookie($tokens->cookie($token));
    }

    public function update(Request $request, int $id, DeviceTokens $tokens): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:64']]);
        $device = TrustedDevice::query()
            ->where('family_id', $this->context->familyId())
            ->whereNull('revoked_at')
            ->findOrFail($id);

        $previous = $device->name;
        $device->fill(['name' => $data['name']])->save();
        $this->audit->log('device.renamed', $device->family_id, $device, [
            'from' => $previous,
            'to' => $device->name,
        ]);

        $current = $tokens->fromRequest($request);

        return response()->json([
            'data' => $this->present($device->load('authorizer.member'), $current?->id === $device->id),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $device = TrustedDevice::query()
            ->where('family_id', $this->context->familyId())
            ->findOrFail($id);

        if ($device->revoked_at === null) {
            $device->forceFill(['revoked_at' => $this->clock->now()])->save();
            $this->audit->log('device.revoked', $device->family_id, $device, ['name' => $device->name]);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TrustedDevice $device, bool $isCurrent): array
    {
        $tz = $this->context->family()->timezone;

        return [
            'id' => $device->id,
            'name' => $device->name,
            'is_current' => $isCurrent,
            'authorized_by' => $device->authorizer->member?->display_name,
            'created_at' => Clock::iso($device->created_at, $tz),
            'last_used_at' => Clock::iso($device->last_used_at, $tz),
            'revoked_at' => Clock::iso($device->revoked_at, $tz),
        ];
    }
}
