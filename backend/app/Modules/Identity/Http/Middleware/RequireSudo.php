<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Identity\Services\Sudo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSudo
{
    public function __construct(private readonly Sudo $sudo) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sudo->active($request->session())) {
            throw ApiException::sudoRequired();
        }

        return $next($request);
    }
}
