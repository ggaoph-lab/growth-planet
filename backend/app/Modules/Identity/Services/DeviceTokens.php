<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\TrustedDevice;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class DeviceTokens
{
    public const COOKIE = 'gp_device';

    private const COOKIE_MINUTES = 60 * 24 * 400;

    /**
     * @return array{0: TrustedDevice, 1: string} the device and the plaintext token (only ever placed in the cookie)
     */
    public function issue(int $familyId, string $name, User $authorizer): array
    {
        $token = Str::random(64);

        $device = new TrustedDevice(['name' => $name]);
        $device->family_id = $familyId;
        $device->authorized_by = $authorizer->id;
        $device->token_hash = self::hash($token);
        $device->save();

        return [$device, $token];
    }

    public function fromRequest(Request $request): ?TrustedDevice
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }

        return TrustedDevice::query()->active()->where('token_hash', self::hash($token))->first();
    }

    public function cookie(string $token): Cookie
    {
        return cookie(
            name: self::COOKIE,
            value: $token,
            minutes: self::COOKIE_MINUTES,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
