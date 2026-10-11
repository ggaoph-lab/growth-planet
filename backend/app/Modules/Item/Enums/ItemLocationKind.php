<?php

namespace App\Modules\Item\Enums;

enum ItemLocationKind: string
{
    case Home = 'home';
    case Bag = 'bag';
    case School = 'school';
    case Desk = 'desk';
    case Other = 'other';
}
