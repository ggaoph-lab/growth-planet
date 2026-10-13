<?php

namespace App\Modules\Core\Settings;

use Illuminate\Support\Facades\Crypt;
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

        if ($row === null) {
            return $def['default'];
        }

        $decoded = json_decode($row->value, true);
        if ($def['type'] === 'secret') {
            return $this->decryptSecret(is_string($decoded) ? $decoded : '');
        }

        return SettingsRegistry::cast($key, $decoded);
    }

    public function set(string $key, mixed $value, ?int $familyId = null, ?int $updatedBy = null): void
    {
        $def = SettingsRegistry::definition($key);
        $cast = SettingsRegistry::cast($key, $value);

        if ($def['type'] === 'secret') {
            $stored = $cast === '' ? '' : Crypt::encryptString((string) $cast);
        } else {
            $stored = $cast;
        }

        $existing = DB::table('system_settings')
            ->where('key', $key)
            ->where(fn ($q) => $familyId === null ? $q->whereNull('family_id') : $q->where('family_id', $familyId))
            ->first();

        $payload = [
            'value' => json_encode($stored, JSON_UNESCAPED_UNICODE),
            'updated_by' => $updatedBy,
            'updated_at' => now('UTC'),
        ];

        if ($existing === null) {
            DB::table('system_settings')->insert([
                'family_id' => $familyId,
                'key' => $key,
                'value' => $payload['value'],
                'updated_by' => $updatedBy,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return;
        }

        DB::table('system_settings')->where('id', $existing->id)->update($payload);
    }

    public function has(string $key, ?int $familyId = null): bool
    {
        return DB::table('system_settings')
            ->where('key', $key)
            ->where(fn ($q) => $familyId === null ? $q->whereNull('family_id') : $q->where('family_id', $familyId))
            ->exists();
    }

    public function forget(string $key, ?int $familyId = null): void
    {
        DB::table('system_settings')
            ->where('key', $key)
            ->where(fn ($q) => $familyId === null ? $q->whereNull('family_id') : $q->where('family_id', $familyId))
            ->delete();
    }

    private function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            // Legacy / plain values should not break reads.
            return $stored;
        }
    }
}
