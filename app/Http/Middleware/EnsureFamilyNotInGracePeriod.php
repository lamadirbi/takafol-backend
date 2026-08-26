<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يمنع رب الأسرة من استخدام «مميزات» المنصة أثناء فترة السماح بعد انتهاء الاشتراك
 * (تعليقات، تفاعلات، إشعارات دفع، طلبات تعديل…).
 */
class EnsureFamilyNotInGracePeriod
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== User::ROLE_FAMILY_HEAD) {
            return $next($request);
        }

        if ($user->camp_id === null) {
            return $next($request);
        }

        $camp = app(TenantManager::class)->campForId((int) $user->camp_id);
        if ($camp && $camp->familiesInGracePeriod()) {
            $amount = (int) config('subscription.monthly_amount_ils', 50);

            return response()->json([
                'message' => 'اشتراك المخيم منتهٍ — فترة سماح: لا يمكن استخدام هذه الميزة حتى يُسدَّد '.$amount.' شيكل شهرياً وتُحدَّث الإدارة تاريخ الاشتراك.',
                'code' => 'subscription_payment_required',
            ], 403);
        }

        return $next($request);
    }
}
