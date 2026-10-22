<?php

namespace App\Modules\Shop\Enums;

enum RewardKind: string
{
    case Physical = 'physical';
    case FamilyActivity = 'family_activity';
    case VirtualDecoration = 'virtual_decoration';
    case ScreenTime = 'screen_time';

    public function pricedInPoints(): bool
    {
        return $this !== self::Physical;
    }
}
