<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\TenantMiddleware::class);
        $middleware->alias([
            'auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'family_subscription' => \App\Http\Middleware\EnsureFamilySubscriptionActive::class,
            'family_no_grace' => \App\Http\Middleware\EnsureFamilyNotInGracePeriod::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // مهم: واجهات API (Sanctum) لا يجب أن تعمل redirect لمسار web باسم login
        // عند عدم وجود توكن/تسجيل دخول، نرجع 401 JSON.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
