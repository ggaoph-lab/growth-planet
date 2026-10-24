<?php

namespace App\Modules\Identity\Services;

use App\Modules\Core\Settings\Settings;
use App\Modules\Core\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * "Sudo mode": a parent re-enters their password and sensitive actions are allowed for a short window.
 */
class Sudo
{
    public const SESSION_KEY = 'sudo_until';

    public function __construct(
        private readonly Clock $clock,
        private readonly Settings $settings,
    ) {}

    public function grant(Session $session, int $familyId): CarbonImmutable
    {
        $minutes = (int) $this->settings->get('auth.sudo_ttl_minutes', $familyId);
        $until = $this->clock->now()->addMinutes($minutes);
        $session->put(self::SESSION_KEY, $until->getTimestamp());

        return $until;
    }

    public function until(Session $session): ?CarbonImmutable
    {
        $ts = $session->get(self::SESSION_KEY);
        if (! is_int($ts) && ! (is_string($ts) && ctype_digit($ts))) {
            return null;
        }

        $until = CarbonImmutable::createFromTimestamp((int) $ts, 'UTC');

        return $until->isAfter($this->clock->now()) ? $until : null;
    }

    public function active(Session $session): bool
    {
        return $this->until($session) !== null;
    }
}
