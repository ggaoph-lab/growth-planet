<?php

namespace App\Modules\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time invite to self-register a new family. Plaintext code is never stored.
 *
 * @property int $id
 * @property string $code_hash
 * @property string $code_prefix
 * @property string|null $note
 * @property int $created_by
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int|null $used_by_family_id
 * @property CarbonImmutable|null $revoked_at
 */
class FamilyInvite extends Model
{
    protected $fillable = ['note', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Family, $this> */
    public function usedByFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'used_by_family_id');
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    public function isAvailable(CarbonImmutable $now): bool
    {
        if ($this->used_at !== null || $this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($now)) {
            return false;
        }

        return true;
    }

    public function status(CarbonImmutable $now): string
    {
        if ($this->used_at !== null) {
            return 'used';
        }
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($now)) {
            return 'expired';
        }

        return 'available';
    }
}
