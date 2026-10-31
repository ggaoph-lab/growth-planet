<?php

namespace App\Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser a parent has authorised for child PIN login. Only the SHA-256 of the token is stored.
 *
 * @property int $id
 * @property int $family_id
 * @property string $name
 * @property string $token_hash
 * @property int $authorized_by
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property-read User $authorizer
 */
class TrustedDevice extends Model
{
    protected $fillable = ['name'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'hub_approvals_enabled' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /** @param Builder<TrustedDevice> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
