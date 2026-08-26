<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(
            [
                'national_id' => ['required', 'string'],
                'serial' => ['required', 'string', 'regex:/^[0-9]{3}$/'],
            ],
            [
                'serial.regex' => 'الرقم التسلسلي يجب أن يكون 3 أرقام (مثال: 002 أو 010 أو 100).',
            ]
        );

        $user = User::query()
            ->with('camp:id,subscription_valid_until,subscription_notice_image_path,name,slug')
            ->where('national_id', $credentials['national_id'])
            ->first();

        if (! $user || ! Hash::check($credentials['serial'], $user->password)) {
            throw ValidationException::withMessages([
                'national_id' => ['رقم الهوية أو الرقم التسلسلي غير صحيح.'],
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'national_id' => ['تسجيل دخول المسؤولين يتم عبر البريد وكلمة المرور من صفحة «دخول الإدارة» فقط.'],
            ]);
        }

        if ($user->isFamilyHead() && $user->camp_id !== null) {
            $camp = $user->relationLoaded('camp') ? $user->camp : $user->camp()->first();
            if ($camp && $camp->familiesHardBlocked()) {
                throw ValidationException::withMessages([
                    'national_id' => ['لا يمكن الدخول: انتهى اشتراك المخيم وبعد فترة السماح لم يُجدَّد الدفع ('.(int) config('subscription.monthly_amount_ils', 50).' شيكل شهرياً). تواصل مع إدارة المخيم أو منصة تَكافل.'],
                ]);
            }
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * تسجيل دخول المسؤولين فقط: username + كلمة المرور (منفصل عن دخول العائلات).
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate(
            [
                'username' => ['required', 'string', 'max:64'],
                'password' => ['required', 'string'],
            ],
            [
                'username.required' => 'اسم المستخدم مطلوب.',
            ]
        );

        $user = User::query()
            ->with('camp:id,primary_admin_user_id,name,slug')
            ->where('username', $credentials['username'])
            ->where('role', User::ROLE_ADMIN)
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['اسم المستخدم أو كلمة المرور غير صحيحة.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing([
            'camp:id,subscription_valid_until,subscription_notice_image_path,primary_admin_user_id,name,slug',
        ]);
        $data = (new UserResource($user))->toArray($request);

        if ($user->isFamilyHead() && $user->camp_id !== null) {
            $camp = $user->relationLoaded('camp') ? $user->camp : $user->camp()->first();
            if ($camp) {
                $data['subscription'] = [
                    'in_grace' => $camp->familiesInGracePeriod(),
                    'hard_blocked' => $camp->familiesHardBlocked(),
                    'notice_image_url' => $camp->subscriptionNoticeImageUrl(),
                    'monthly_amount_ils' => (int) config('subscription.monthly_amount_ils', 50),
                    'grace_days' => $camp->graceDays(),
                ];
            }
        }

        return response()->json($data);
    }
}
