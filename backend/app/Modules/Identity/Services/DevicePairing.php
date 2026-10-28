<?php

namespace App\Modules\Identity\Services;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class DevicePairing
{
    private const CODE_SECONDS = 600;

    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly Clock $clock,
        private readonly DeviceTokens $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{code: string, expires_at: string} */
    public function create(int $familyId, User $parent): array
    {
        return DB::transaction(function () use ($familyId, $parent) {
            $now = $this->clock->now();
            DB::table('families')->where('id', $familyId)->lockForUpdate()->first();
            // Only the newest code for a family remains usable.
            DB::table('device_pairing_codes')
                ->where('family_id', $familyId)
                ->whereNull('used_at')
                ->update(['used_at' => $now]);

            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $expires = $now->addSeconds(self::CODE_SECONDS);
            DB::table('device_pairing_codes')->insert([
                'family_id' => $familyId,
                'created_by' => $parent->id,
                'code_hash' => $this->hash($code),
                'expires_at' => $expires,
                'used_at' => null,
                'created_at' => $now,
            ]);
            $this->audit->log('device.pair_code_created', $familyId, $parent, actor: $parent);

            return ['code' => $code, 'expires_at' => $expires->toIso8601String()];
        });
    }

    /** @return array{0: TrustedDevice, 1: string} */
    public function redeem(string $code, string $name, string $ip): array
    {
        $key = 'device-pair:'.$ip;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
        }

        $result = DB::transaction(function () use ($code, $name) {
            $pair = DB::table('device_pairing_codes')
                ->where('code_hash', $this->hash($code))
                ->lockForUpdate()
                ->first();
            if ($pair === null || $pair->used_at !== null || $pair->expires_at <= $this->clock->now()->toDateTimeString()) {
                return null;
            }

            $parent = User::query()->find($pair->created_by);
            if ($parent === null
                || $parent->member?->family_id !== (int) $pair->family_id
                || $parent->member?->role !== MemberRole::Parent
                || $parent->member?->status !== MemberStatus::Active) {
                return null;
            }

            [$device, $token] = $this->tokens->issue((int) $pair->family_id, $name, $parent);
            DB::table('device_pairing_codes')->where('id', $pair->id)->update(['used_at' => $this->clock->now()]);
            $this->audit->log('device.paired', (int) $pair->family_id, $device, ['name' => $name], $parent);

            return [$device, $token];
        });

        if ($result === null) {
            RateLimiter::hit($key, self::CODE_SECONDS);
            throw new ApiException('INVALID_PAIRING_CODE', BiMsg::of('配对码无效或已过期', 'Pairing code is invalid or expired'), 422);
        }

        RateLimiter::clear($key);

        return $result;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
