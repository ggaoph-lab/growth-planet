<?php

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Models\CoinLedgerEntry;
use App\Modules\Ledger\Models\PointLedgerEntry;
use App\Modules\Ledger\Models\XpLedgerEntry;
use Illuminate\Support\Collection;

class LedgerEntryPresenter
{
    /**
     * @param  Collection<int, XpLedgerEntry|PointLedgerEntry|CoinLedgerEntry>  $entries
     * @param  'xp'|'point'|'coin'  $asset
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $entries, string $asset): array
    {
        return app(LedgerEntryEnricher::class)->present($entries, $asset);
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(XpLedgerEntry|PointLedgerEntry|CoinLedgerEntry $entry, string $asset): array
    {
        return self::collection(collect([$entry]), $asset)[0];
    }
}
