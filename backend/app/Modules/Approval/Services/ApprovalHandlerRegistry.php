<?php

namespace App\Modules\Approval\Services;

use App\Modules\Approval\Contracts\ApprovalHandler;
use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Support\BiMsg;

class ApprovalHandlerRegistry
{
    /** @var array<string, ApprovalHandler> */
    private array $handlers = [];

    public function register(ApprovalHandler $handler): void
    {
        $this->handlers[$handler->type()] = $handler;
    }

    public function get(string $type): ApprovalHandler
    {
        if (! isset($this->handlers[$type])) {
            throw new ApiException('INVALID_STATE', BiMsg::of('未注册的审核类型', 'Unregistered approval type'), 500);
        }

        return $this->handlers[$type];
    }
}
