<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Models\DeductionPreset;

class DeductionPresetSeeder
{
    /**
     * Seed a few starter presets the first time a family opens the list.
     *
     * @return list<array{title: string, point_amount: int, coin_amount: int, sort: int}>
     */
    public static function defaults(): array
    {
        return [
            ['title' => '晚睡', 'point_amount' => 2, 'coin_amount' => 0, 'sort' => 10],
            ['title' => '忘记收拾书桌', 'point_amount' => 1, 'coin_amount' => 0, 'sort' => 20],
            ['title' => '顶嘴', 'point_amount' => 3, 'coin_amount' => 0, 'sort' => 30],
            ['title' => '没有刷牙', 'point_amount' => 1, 'coin_amount' => 0, 'sort' => 40],
            ['title' => '乱扔东西', 'point_amount' => 1, 'coin_amount' => 1, 'sort' => 50],
        ];
    }

    public function ensureDefaults(int $familyId): void
    {
        if (DeductionPreset::query()->where('family_id', $familyId)->exists()) {
            return;
        }

        foreach (self::defaults() as $row) {
            DeductionPreset::query()->create([
                'family_id' => $familyId,
                'title' => $row['title'],
                'point_amount' => $row['point_amount'],
                'coin_amount' => $row['coin_amount'],
                'sort' => $row['sort'],
                'is_active' => true,
            ]);
        }
    }
}
