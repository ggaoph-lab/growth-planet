<?php

namespace App\Modules\Ledger\Enums;

enum LedgerEntryType: string
{
    case Reward = 'reward';
    case Redemption = 'redemption';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case ParentBonus = 'parent_bonus';
    case LevelUp = 'level_up';
    case DailyAllowance = 'daily_allowance';
}
