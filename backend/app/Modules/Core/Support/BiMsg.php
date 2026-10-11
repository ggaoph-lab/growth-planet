<?php

namespace App\Modules\Core\Support;

/**
 * Formats user-facing API messages as Chinese / English bilingual text.
 */
final class BiMsg
{
    public static function of(string $zh, string $en): string
    {
        return $zh === $en ? $zh : "{$zh} / {$en}";
    }
}
