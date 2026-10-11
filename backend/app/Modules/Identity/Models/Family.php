<?php

namespace App\Modules\Identity\Models;

use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $timezone
 * @property string $locale
 * @property int $week_starts_on
 */
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    protected $fillable = ['name', 'timezone', 'locale'];

    protected static function newFactory(): FamilyFactory
    {
        return FamilyFactory::new();
    }

    /** @return HasMany<FamilyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    /** @return HasMany<ChildProfile, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ChildProfile::class);
    }

    /** @return HasMany<TrustedDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }
}
