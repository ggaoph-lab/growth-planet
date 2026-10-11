<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChildProfile
 */
class ParentChildResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tz = $this->family->timezone;

        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'avatar' => $this->avatar,
            'birth_date' => $this->birth_date,
            'age' => app(Clock::class)->ageToday($this->birth_date, $tz),
            'grade' => $this->grade,
            'theme_color' => $this->theme_color,
            'status' => $this->status->value,
            'pin_enabled' => $this->member->user->pin_hash !== null,
            'preferences' => $this->resolvedPreferences(),
            'created_at' => Clock::iso($this->created_at, $tz),
            'updated_at' => Clock::iso($this->updated_at, $tz),
        ];
    }
}
