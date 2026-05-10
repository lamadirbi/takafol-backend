<?php

namespace App\Http\Middleware;

use App\Models\Camp;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يمنع رب الأسرة من استخدام API إذا انتهى اشتراك المخيم (لم يُجدَّد الدفع).
 */
class EnsureFamilySubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // السماح بتسجيل الخروج حتى يُزال التوكن المحلي عند انتهاء الاشتراك
        if ($request->isMethod('POST') && str_ends_with($request->path(), 'logout')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || $user->role !== User::ROLE_FAMILY_HEAD) {
            return $next($request);
        }

        if ($user->camp_id === null) {
            return $next($request);
        }

        $camp = Camp::query()->find($user->camp_id);
        if (! $camp || ! $camp->familiesAccessAllowed()) {
            return response()->json([
                'message' => 'انتهى اشتراك المخيم وفترة السماح؛ لا يمكن الاستمرار حتى يُحدَّد تاريخ اشتراك جديد من إدارة المنصة.',
                'code' => 'subscription_expired',
            ], 403);
        }

        return $next($request);
    }
}
