<?php

namespace App\Modules\Ledger\Enums;

enum PointHoldStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';
}
