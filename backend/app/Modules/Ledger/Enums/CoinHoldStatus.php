<?php

namespace App\Modules\Ledger\Enums;

enum CoinHoldStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';
}
