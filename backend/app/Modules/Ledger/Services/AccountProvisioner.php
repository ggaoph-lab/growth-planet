<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Identity\Models\ChildProfile;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\XpAccount;

class AccountProvisioner
{
    public function forChild(ChildProfile $child): void
    {
        XpAccount::query()->firstOrCreate(
            ['child_id' => $child->id],
            ['family_id' => $child->family_id, 'balance' => 0, 'lifetime_earned' => 0, 'lock_version' => 0, 'updated_at' => now()],
        );

        CoinAccount::query()->firstOrCreate(
            ['child_id' => $child->id],
            ['family_id' => $child->family_id, 'balance' => 0, 'held' => 0, 'lifetime_earned' => 0, 'lock_version' => 0, 'updated_at' => now()],
        );
    }
}
