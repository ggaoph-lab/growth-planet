<?php

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Http\Middleware\EnsureRole;
use App\Modules\Identity\Http\Resources\MePresenter;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Identity\Services\PinHasher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class KidAuthController
{
    private const MAX_PIN_ATTEMPTS = 5;

    private const PIN_DECAY_SECONDS = 10 * 60;

    public function __construct(
        private readonly DeviceTokens $devices,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function device(Request $request): JsonResponse
    {
        $device = $this->devices->fromRequest($request);

        return response()->json(['data' => [
            'authorized' => $device !== null,
            'name' => $device?->name,
        ]]);
    }

    public function profiles(Request $request): JsonResponse
    {
        $device = $this->requireDevice($request);

        $children = $this->loginableChildren($device)->get()->map(function (ChildProfile $c) {
            $user = $c->member->user;
            $pinRequired = $user->pin_hash !== null;

            return [
                'id' => $c->id,
                'nickname' => $c->nickname,
                'avatar' => $c->avatar,
                'theme_color' => $c->theme_color,
                'pin_required' => $pinRequired,
                'pin_length' => $pinRequired ? (int) ($user->pin_length ?? 4) : null,
            ];
        });

        return response()->json(['data' => $children]);
    }

    public function login(Request $request, MePresenter $me): JsonResponse
    {
        $device = $this->requireDevice($request);
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'pin' => ['nullable', 'string', 'max:6'],
        ]);

        $child = $this->loginableChildren($device)->find($data['child_id']);
        if ($child === null) {
            throw new ApiException('NOT_FOUND', BiMsg::of('内容不存在', 'Not found'), 404);
        }

        $throttleKey = 'kid-pin:'.$device->id.'|'.$child->id;
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_PIN_ATTEMPTS)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }

        $user = $child->member->user;
        $pinHash = $user->pin_hash;
        if ($pinHash !== null && ! PinHasher::check((string) ($data['pin'] ?? ''), $pinHash)) {
            RateLimiter::hit($throttleKey, self::PIN_DECAY_SECONDS);
            $this->audit->log('auth.kid_login_failed', $child->family_id, $child, ['device_id' => $device->id], $user);

            throw new ApiException('INVALID_PIN', BiMsg::of('密码不对，再试一次吧', 'Wrong PIN, try again'), 422);
        }

        RateLimiter::clear($throttleKey);
        $user->last_login_at = $this->clock->now();
        // Upgrade legacy bcrypt PINs to HMAC so the next login skips the slow hash on NAS CPUs.
        if ($pinHash !== null && PinHasher::needsRehash($pinHash)) {
            $user->pin_hash = PinHasher::make((string) $data['pin']);
        }
        $user->save();
        $device->forceFill(['last_used_at' => $this->clock->now()])->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureRole::SESSION_DEVICE_KEY, $device->id);

        $user->loadMissing(['member.family', 'member.childProfile']);
        $this->audit->log('auth.kid_login', $child->family_id, $child, ['device_id' => $device->id], $user);

        return response()->json(['data' => $me->present($user, $request->session())]);
    }

    private function requireDevice(Request $request): TrustedDevice
    {
        return $this->devices->fromRequest($request)
            ?? throw new ApiException('DEVICE_NOT_AUTHORIZED', BiMsg::of('这台设备还没有被家长授权', 'This device has not been authorized by a parent'), 401);
    }

    /**
     * @return Builder<ChildProfile>
     */
    private function loginableChildren(TrustedDevice $device)
    {
        return ChildProfile::query()
            ->with('member.user')
            ->where('family_id', $device->family_id)
            ->where('status', ChildStatus::Active)
            ->whereHas('member', fn ($q) => $q->where('status', MemberStatus::Active))
            ->orderBy('id');
    }
}
