<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

trait ResolvesOptionalSanctumUser
{
    protected function resolveOptionalSanctumUser(Request $request): ?User
    {
        if ($user = $request->user('sanctum')) {
            return $user;
        }

        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        if ($user instanceof User) {
            $request->setUserResolver(static fn () => $user);
        }

        return $user instanceof User ? $user : null;
    }
}
