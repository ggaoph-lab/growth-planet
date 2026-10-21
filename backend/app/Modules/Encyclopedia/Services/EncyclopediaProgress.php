<?php

namespace App\Modules\Encyclopedia\Services;

use App\Modules\Encyclopedia\Models\EncyclopediaRead;
use App\Modules\Identity\Models\ChildProfile;
use Carbon\CarbonImmutable;

class EncyclopediaProgress
{
    /**
     * @return list<string>
     */
    public function readTopicIds(ChildProfile $child): array
    {
        return EncyclopediaRead::query()
            ->where('child_id', $child->id)
            ->pluck('topic_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function markRead(ChildProfile $child, string $topicId): EncyclopediaRead
    {
        $now = CarbonImmutable::now('UTC');

        return EncyclopediaRead::query()->updateOrCreate(
            [
                'child_id' => $child->id,
                'topic_id' => $topicId,
            ],
            [
                'family_id' => $child->family_id,
                'read_at' => $now,
            ],
        );
    }
}
