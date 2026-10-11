<?php

namespace App\Modules\Item\Services;

use App\Modules\Item\Enums\ItemLocationKind;
use App\Modules\Item\Models\ItemLocation;

class ItemLocationSeeder
{
    /**
     * @return list<ItemLocation>
     */
    public function seedForFamily(int $familyId): array
    {
        $defaults = [
            [ItemLocationKind::Home, '家里', 1],
            [ItemLocationKind::Bag, '书包', 2],
            [ItemLocationKind::School, '学校', 3],
            [ItemLocationKind::Desk, '书桌', 4],
        ];

        $created = [];
        foreach ($defaults as [$kind, $name, $sort]) {
            $existing = ItemLocation::query()
                ->where('family_id', $familyId)
                ->where('kind', $kind)
                ->where('is_system', true)
                ->first();
            if ($existing !== null) {
                $created[] = $existing;

                continue;
            }
            $created[] = ItemLocation::query()->create([
                'family_id' => $familyId,
                'kind' => $kind,
                'name' => $name,
                'is_system' => true,
                'sort' => $sort,
            ]);
        }

        return $created;
    }

    public function defaultHomeLocationId(int $familyId): int
    {
        $locations = $this->seedForFamily($familyId);

        foreach ($locations as $loc) {
            if ($loc->kind === ItemLocationKind::Home) {
                return $loc->id;
            }
        }

        return $locations[0]->id;
    }
}
