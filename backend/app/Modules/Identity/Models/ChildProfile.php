<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\ChildStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $family_id
 * @property int $member_id
 * @property string $nickname
 * @property string $avatar
 * @property string $birth_date Y-m-d, encrypted at rest
 * @property int $grade
 * @property string $theme_color
 * @property ChildStatus $status
 * @property array{animations: bool, sounds: bool} $preferences
 * @property-read Family $family
 * @property-read FamilyMember $member
 */
class ChildProfile extends Model
{
    public const DEFAULT_PREFERENCES = ['animations' => true, 'sounds' => true];

    protected $fillable = ['nickname', 'avatar', 'grade', 'theme_color'];

    protected $hidden = ['birth_date'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'encrypted',
            'status' => ChildStatus::class,
            'preferences' => 'array',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<FamilyMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'member_id');
    }

    /**
     * @return array{animations: bool, sounds: bool}
     */
    public function resolvedPreferences(): array
    {
        return array_merge(self::DEFAULT_PREFERENCES, array_intersect_key($this->preferences ?? [], self::DEFAULT_PREFERENCES));
    }
}
