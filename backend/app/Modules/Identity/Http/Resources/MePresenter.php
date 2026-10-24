<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\Sudo;
use Illuminate\Contracts\Session\Session;

class MePresenter
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Sudo $sudo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user, Session $session): array
    {
        $member = $user->member;
        $family = $member->family;
        $isChild = $member->role === MemberRole::Child;

        return [
            'user' => [
                'id' => $user->id,
                'role' => $member->role->value,
                'display_name' => $member->display_name,
                'username' => $isChild ? null : $user->username,
                'is_site_admin' => $isChild ? false : (bool) $user->is_site_admin,
            ],
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'timezone' => $family->timezone,
                'locale' => $family->locale,
            ],
            'child' => $isChild ? (new KidProfileResource($member->childProfile))->resolve() : null,
            'sudo_until' => $isChild ? null : Clock::iso($this->sudo->until($session), $family->timezone),
            'server_time' => Clock::iso($this->clock->now(), $family->timezone),
        ];
    }
}
