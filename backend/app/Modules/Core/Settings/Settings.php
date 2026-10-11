<?php

namespace App\Modules\Core\Settings;

use Illuminate\Support\Facades\DB;

class Settings
{
    public function get(string $key, ?int $familyId = null): mixed
    {
        $def = SettingsRegistry::definition($key);

        $rows = DB::table('system_settings')
            ->where('key', $key)
            ->where(fn ($q) => $q->whereNull('family_id')->when($familyId, fn ($q) => $q->orWhere('family_id', $familyId)))
            ->get(['family_id', 'value']);

        $row = ($familyId !== null ? $rows->firstWhere('family_id', $familyId) : null)
            ?? $rows->firstWhere('family_id', null);

        return $row === null ? $def['default'] : SettingsRegistry::cast($key, json_decode($row->value, true));
    }
}
