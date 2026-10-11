<?php

namespace App\Modules\Shop\Enums;

enum RewardStatus: string
{
    case Draft = 'draft';
    case OnShelf = 'on_shelf';
    case OffShelf = 'off_shelf';
}
