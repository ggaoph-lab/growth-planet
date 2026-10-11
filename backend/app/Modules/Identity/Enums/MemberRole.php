<?php

namespace App\Modules\Identity\Enums;

enum MemberRole: string
{
    case Parent = 'parent';
    case Child = 'child';
}
