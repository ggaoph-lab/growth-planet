<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Models\ChildProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a child may see about themselves: age, never the birth date.
 *
 * @mixin ChildProfile
 */
class KidProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'avatar' => $this->avatar,
            'theme_color' => $this->theme_color,
            'grade' => $this->grade,
            'age' => app(Clock::class)->ageToday($this->birth_date, $this->family->timezone),
            'preferences' => $this->resolvedPreferences(),
        ];
    }
}
