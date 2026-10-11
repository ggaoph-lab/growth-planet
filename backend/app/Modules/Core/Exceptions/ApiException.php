<?php

namespace App\Modules\Core\Exceptions;

use App\Modules\Core\Support\BiMsg;
use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function sudoRequired(): self
    {
        return new self('SUDO_REQUIRED', BiMsg::of('此操作需要再次输入家长密码确认', 'Please enter the parent password again to confirm'), 423);
    }

    public static function tooManyAttempts(int $retryAfterSeconds): self
    {
        return new self('TOO_MANY_ATTEMPTS', BiMsg::of('尝试次数过多，请稍后再试', 'Too many attempts, please try again later'), 429, ['retry_after' => $retryAfterSeconds]);
    }
}
