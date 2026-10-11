<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\Family;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Models\User;
use App\Modules\Item\Services\ItemLocationSeeder;
use App\Modules\Ledger\Services\RewardPolicySeeder;
use Illuminate\Support\Facades\DB;

class CreateFamilyWithParent
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RewardPolicySeeder $policySeeder,
        private readonly ItemLocationSeeder $itemLocations,
    ) {}

    /**
     * @return array{family: Family, parent: User}
     */
    public function handle(
        string $familyName,
        string $timezone,
        string $parentDisplayName,
        string $username,
        string $password,
        ?string $email = null,
    ): array {
        return DB::transaction(function () use ($familyName, $timezone, $parentDisplayName, $username, $password, $email) {
            $family = Family::create(['name' => $familyName, 'timezone' => $timezone]);

            $user = User::create(['username' => $username, 'email' => $email, 'password' => $password]);

            $member = new FamilyMember(['display_name' => $parentDisplayName]);
            $member->family_id = $family->id;
            $member->user_id = $user->id;
            $member->role = MemberRole::Parent;
            $member->status = MemberStatus::Active;
            $member->save();

            $this->audit->log('family.created', $family->id, $family, ['name' => $familyName, 'timezone' => $timezone], $user);

            $this->policySeeder->seedForFamily($family->id);
            $this->itemLocations->seedForFamily($family->id);

            return ['family' => $family, 'parent' => $user->load('member')];
        });
    }
}
