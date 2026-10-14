<?php

namespace App\Modules\Task\Support;

/**
 * Last sanitized LLM failure code for the current request (English Lab / homework).
 * Codes are short and safe to show in the kid UI.
 */
class LlmFailureTracker
{
    private static ?string $code = null;

    public static function clear(): void
    {
        self::$code = null;
    }

    public static function set(string $code): void
    {
        self::$code = $code;
    }

    public static function peek(): ?string
    {
        return self::$code;
    }

    public static function pull(): ?string
    {
        $code = self::$code;
        self::$code = null;

        return $code;
    }

    public static function fromHttpStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'http_401',
            $status === 429 => 'http_429',
            $status >= 500 => 'http_5xx',
            $status >= 400 => 'http_4xx',
            default => 'unreachable',
        };
    }
}
