<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Identity\Enums\ChildStatus;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Services\AccountProvisioner;
use App\Modules\ScreenTime\Services\ScreenTimePolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateChild
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountProvisioner $accounts,
        private readonly ScreenTimePolicySeeder $screenPolicies,
    ) {}

    /**
     * @param  array{nickname: string, avatar: string, birth_date: string, grade: int, theme_color: string, pin?: string|null}  $data
     */
    public function handle(int $familyId, array $data, ?User $actor = null): ChildProfile
    {
        return DB::transaction(function () use ($familyId, $data, $actor) {
            $user = new User;
            $user->pin_hash = isset($data['pin']) ? Hash::make($data['pin']) : null;
            $user->save();

            $member = new FamilyMember(['display_name' => $data['nickname']]);
            $member->family_id = $familyId;
            $member->user_id = $user->id;
            $member->role = MemberRole::Child;
            $member->status = MemberStatus::Active;
            $member->save();

            $child = new ChildProfile([
                'nickname' => $data['nickname'],
                'avatar' => $data['avatar'],
                'grade' => $data['grade'],
                'theme_color' => $data['theme_color'],
            ]);
            $child->family_id = $familyId;
            $child->member_id = $member->id;
            $child->birth_date = $data['birth_date'];
            $child->status = ChildStatus::Active;
            $child->preferences = ChildProfile::DEFAULT_PREFERENCES;
            $child->save();

            $this->accounts->forChild($child);
            $child->load('family');
            $this->screenPolicies->seedForChild($child);

            $this->audit->log('child.created', $familyId, $child, [
                'nickname' => $data['nickname'],
                'grade' => $data['grade'],
                'birth_date' => true,
                'pin' => isset($data['pin']),
            ], $actor);

            return $child;
        });
    }
}
