<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires the authenticated user to be a site admin (not a family role). */
class EnsureSiteAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_site_admin) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
