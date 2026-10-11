<?php

namespace App\Modules\Ledger\Enums;

enum RewardGrantStatus: string
{
    case PendingApproval = 'pending_approval';
    case Granted = 'granted';
    case Rejected = 'rejected';
    case Capped = 'capped';
    case Reversed = 'reversed';
}
