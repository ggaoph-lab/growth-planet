<?php

namespace App\Modules\Identity\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
