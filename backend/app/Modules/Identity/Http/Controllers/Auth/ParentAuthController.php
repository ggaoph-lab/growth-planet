<?php

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Http\Resources\MePresenter;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Sudo;
use App\Modules\Identity\Support\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ParentAuthController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 15 * 60;

    /** Account lock starts after this many consecutive failures and doubles each time (minutes, capped). */
    private const LOCK_AFTER_FAILURES = 5;

    private const MAX_LOCK_MINUTES = 60;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly MePresenter $me,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $login = Str::lower(trim($data['login']));
        $throttleKey = 'parent-login:'.$login.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }

        $user = User::query()
            ->with(['member.family', 'member.childProfile'])
            ->where(fn ($q) => $q->where('username', $login)->orWhere('email', $login))
            ->whereNotNull('password')
            ->first();

        if ($user?->locked_until?->isAfter($this->clock->now())) {
            throw ApiException::tooManyAttempts((int) ceil($this->clock->now()->diffInSeconds($user->locked_until, true)));
        }

        $member = $user?->member;
        $valid = $user !== null
            && Hash::check($data['password'], (string) $user->password)
            && $member?->role === MemberRole::Parent
            && $member->status === MemberStatus::Active;

        if (! $valid) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            if ($user !== null) {
                $this->recordFailure($user);
            }
            $this->audit->log('auth.login_failed', $member?->family_id, $user, ['login' => $login]);

            throw new ApiException('INVALID_CREDENTIALS', BiMsg::of('账号或密码不正确', 'Incorrect username or password'), 422);
        }

        RateLimiter::clear($throttleKey);
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->last_login_at = $this->clock->now();
        // Rehash when BCRYPT_ROUNDS drops (e.g. NAS tuning) so later logins stay cheap.
        if (Hash::needsRehash((string) $user->password)) {
            $user->password = $data['password'];
        }
        $user->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->audit->log('auth.login', $member->family_id, $user, actor: $user);

        return response()->json(['data' => $this->me->present($user, $request->session())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->audit->log('auth.logout', $user->member?->family_id, $user, actor: $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request, CurrentContext $context): JsonResponse
    {
        return response()->json(['data' => $this->me->present($context->user(), $request->session())]);
    }

    public function confirmPassword(Request $request, CurrentContext $context, Sudo $sudo): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $user = $context->user();
        $throttleKey = 'sudo:'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($throttleKey));
        }

        if (! Hash::check($data['password'], (string) $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $this->audit->log('auth.sudo_failed', $context->familyId(), $user);

            throw new ApiException('INVALID_CREDENTIALS', BiMsg::of('密码不正确', 'Incorrect password'), 422);
        }

        RateLimiter::clear($throttleKey);
        $until = $sudo->grant($request->session(), $context->familyId());
        $this->audit->log('auth.sudo_granted', $context->familyId(), $user);

        return response()->json(['data' => ['sudo_until' => Clock::iso($until, $context->family()->timezone)]]);
    }

    private function recordFailure(User $user): void
    {
        $failures = $user->failed_login_count + 1;
        $lockedUntil = null;
        if ($failures >= self::LOCK_AFTER_FAILURES) {
            $minutes = min(self::MAX_LOCK_MINUTES, 2 ** ($failures - self::LOCK_AFTER_FAILURES));
            $lockedUntil = $this->clock->now()->addMinutes($minutes);
        }

        $user->forceFill(['failed_login_count' => $failures, 'locked_until' => $lockedUntil])->save();
    }
}
