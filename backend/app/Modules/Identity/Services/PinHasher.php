<?php

namespace App\Modules\Identity\Services;

use Illuminate\Support\Facades\Hash;

/**
 * Short kid PINs are rate-limited, so a keyed HMAC is enough and far cheaper than bcrypt
 * on modest NAS CPUs. Legacy bcrypt hashes are still accepted and upgraded on success.
 */
final class PinHasher
{
    private const PREFIX = 'hmac:';

    public static function make(string $pin): string
    {
        return self::PREFIX.hash_hmac('sha256', $pin, (string) config('app.key'));
    }

    public static function check(string $pin, string $hash): bool
    {
        if (str_starts_with($hash, self::PREFIX)) {
            return hash_equals($hash, self::make($pin));
        }

        return Hash::check($pin, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return ! str_starts_with($hash, self::PREFIX);
    }
}
