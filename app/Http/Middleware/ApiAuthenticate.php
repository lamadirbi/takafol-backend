<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * API-only authenticate: never redirect to a named 'login' route.
 * When unauthenticated, Laravel should return 401 JSON for API requests.
 */
class ApiAuthenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}

