<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\MemberRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Login identity. Role and family come from the related FamilyMember.
 *
 * @property int $id
 * @property string|null $username
 * @property string|null $email
 * @property string|null $password
 * @property string|null $pin_hash
 * @property int|null $pin_length
 * @property int $failed_login_count
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $last_login_at
 * @property bool $is_site_admin
 * @property-read FamilyMember|null $member
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    protected $fillable = ['username', 'email', 'password'];

    /** Defaults mirror the column defaults so freshly created models are complete under strict mode. */
    protected $attributes = [
        'pin_hash' => null,
        'failed_login_count' => 0,
        'locked_until' => null,
        'last_login_at' => null,
        'remember_token' => null,
        'is_site_admin' => false,
    ];

    protected $hidden = ['password', 'pin_hash', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'is_site_admin' => 'boolean',
        ];
    }

    /** @return HasOne<FamilyMember, $this> */
    public function member(): HasOne
    {
        return $this->hasOne(FamilyMember::class);
    }

    public function isParent(): bool
    {
        return $this->member?->role === MemberRole::Parent;
    }

    public function isChild(): bool
    {
        return $this->member?->role === MemberRole::Child;
    }
}
