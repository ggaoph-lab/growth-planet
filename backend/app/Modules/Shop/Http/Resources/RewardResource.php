<?php

namespace App\Modules\Shop\Http\Resources;

use App\Modules\Shop\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reward */
class RewardResource extends JsonResource
{
    public function __construct($resource, private readonly string $audience = 'parent')
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Reward $reward */
        $reward = $this->resource;
        $hasImage = $reward->image_attachment_id !== null;

        return [
            'id' => $reward->id,
            'family_id' => $reward->family_id,
            'name' => $reward->name,
            'description' => $reward->description,
            'kind' => $reward->kind->value,
            'coin_price' => $reward->coin_price,
            'stock' => $reward->stock,
            'requires_approval' => $reward->requires_approval,
            'status' => $reward->status->value,
            'version' => $reward->version,
            'image_attachment_id' => $reward->image_attachment_id,
            'image_url' => $hasImage
                ? ($this->audience === 'kid'
                    ? "/api/v1/kid/shop/{$reward->id}/image"
                    : "/api/v1/parent/rewards/{$reward->id}/image")
                : null,
            'created_at' => $reward->created_at,
            'updated_at' => $reward->updated_at,
        ];
    }
}
