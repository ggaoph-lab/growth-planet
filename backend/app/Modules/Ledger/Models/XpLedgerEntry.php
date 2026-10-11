<?php

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Enums\SourceModule;
use Illuminate\Database\Eloquent\Model;

class XpLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'xp_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'entry_type' => LedgerEntryType::class,
            'source_module' => SourceModule::class,
            'business_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
