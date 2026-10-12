<?php

namespace App\Modules\Task\Services;

use Illuminate\Http\Client\ConnectionException;

/**
 * Raised only for connection timeouts so the parser can try the other engine.
 * Other connection or HTTP failures must not switch providers.
 */
class HomeworkLlmTimeoutException extends \RuntimeException
{
    public function __construct(public readonly string $provider, ?\Throwable $previous = null)
    {
        parent::__construct('LLM connection timed out: '.$provider, 0, $previous);
    }

    public static function throwIfTimeout(string $provider, ConnectionException $e): void
    {
        $message = strtolower($e->getMessage());
        $timedOut = str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'timeout was reached');

        if ($timedOut) {
            throw new self($provider, $e);
        }
    }
}
