<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DeviceTokens;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * role:parent or role:child. Child sessions are additionally bound to a still-authorised device.
 */
class EnsureRole
{
    public const SESSION_DEVICE_KEY = 'kid_device_id';

    public function __construct(private readonly DeviceTokens $devices) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $member = $user instanceof User ? $user->member : null;

        if ($member === null || $member->status !== MemberStatus::Active) {
            throw new AuthenticationException;
        }

        if ($member->role !== MemberRole::from($role)) {
            throw new AuthorizationException;
        }

        if ($member->role === MemberRole::Child) {
            $this->assertChildSessionValid($request, $member->family_id, $member->childProfile?->status);
        }

        return $next($request);
    }

    private function assertChildSessionValid(Request $request, int $familyId, ?ChildStatus $status): void
    {
        $device = $this->devices->fromRequest($request);
        $sessionDeviceId = $request->session()->get(self::SESSION_DEVICE_KEY);

        $valid = $status === ChildStatus::Active
            && $device !== null
            && $device->family_id === $familyId
            && $device->id === $sessionDeviceId;

        if (! $valid) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new AuthenticationException;
        }
    }
}
