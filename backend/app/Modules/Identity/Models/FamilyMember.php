<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $family_id
 * @property int $user_id
 * @property MemberRole $role
 * @property string $display_name
 * @property MemberStatus $status
 * @property-read Family $family
 * @property-read User $user
 * @property-read ChildProfile|null $childProfile
 */
class FamilyMember extends Model
{
    protected $fillable = ['display_name'];

    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'status' => MemberStatus::class,
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<ChildProfile, $this> */
    public function childProfile(): HasOne
    {
        return $this->hasOne(ChildProfile::class, 'member_id');
    }
}
