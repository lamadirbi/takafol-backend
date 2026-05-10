<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * @param  string  ...$roles  Allowed roles (e.g. admin, family_head)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(Response::HTTP_FORBIDDEN, __('Unauthorized for this role.'));
        }

        return $next($request);
    }
}
