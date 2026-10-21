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
            if ($request->is('api/v1/kid/*')) {
                $planet = \App\Modules\Identity\Support\KidPlanets::forEndpoint((string) $request->segment(4));
                if ($planet !== null && ! \App\Modules\Identity\Support\KidPlanets::enabled($planet, $member->family_id)) {
                    throw new \App\Modules\Core\Exceptions\ApiException(
                        'PLANET_DISABLED',
                        \App\Modules\Core\Support\BiMsg::of('家长暂时关闭了这个星球', 'This planet has been turned off by your parent'),
                        403,
                    );
                }
            }
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
