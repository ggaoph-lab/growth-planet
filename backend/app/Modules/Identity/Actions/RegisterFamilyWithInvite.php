<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\FamilyInvite;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterFamilyWithInvite
{
    public function __construct(
        private readonly CreateFamilyWithParent $createFamily,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{family_id: int, username: string}
     */
    public function handle(
        string $inviteCode,
        string $familyName,
        string $timezone,
        string $parentDisplayName,
        string $username,
        string $password,
    ): array {
        $username = Str::lower(trim($username));
        $code = strtoupper(trim($inviteCode));

        return DB::transaction(function () use ($code, $familyName, $timezone, $parentDisplayName, $username, $password) {
            if (User::query()->where('username', $username)->exists()) {
                throw new ApiException(
                    'VALIDATION_FAILED',
                    BiMsg::of('该用户名已存在', 'This username is already taken'),
                    422,
                );
            }

            $invite = FamilyInvite::query()
                ->where('code_hash', FamilyInvite::hashCode($code))
                ->lockForUpdate()
                ->first();

            $now = $this->clock->now();
            if ($invite === null || ! $invite->isAvailable($now)) {
                throw new ApiException(
                    'INVALID_INVITE',
                    BiMsg::of('邀请码无效或已使用', 'Invite code is invalid or already used'),
                    422,
                );
            }

            $result = $this->createFamily->handle(
                $familyName,
                $timezone,
                $parentDisplayName,
                $username,
                $password,
            );

            $invite->used_at = $now;
            $invite->used_by_family_id = $result['family']->id;
            $invite->save();

            $this->audit->log(
                'invite.consumed',
                $result['family']->id,
                $invite,
                ['invite_id' => $invite->id, 'code_prefix' => $invite->code_prefix],
                $result['parent'],
            );
            $this->audit->log(
                'family.registered',
                $result['family']->id,
                $result['family'],
                ['name' => $familyName, 'timezone' => $timezone, 'invite_id' => $invite->id],
                $result['parent'],
            );

            return [
                'family_id' => $result['family']->id,
                'username' => $username,
            ];
        });
    }
}
