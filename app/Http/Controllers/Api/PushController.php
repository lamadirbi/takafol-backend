<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\InstantPushService;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(
        private readonly WebPushService $webPush,
        private readonly InstantPushService $instantPush,
    ) {
    }

    public function publicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => $this->webPush->vapidPublicKey(),
            'enabled' => $this->webPush->isConfigured(),
        ]);
    }

    public function instantApp(): JsonResponse
    {
        return response()->json($this->instantPush->appInfo());
    }

    public function instantChannel(Request $request): JsonResponse
    {
        $deviceKey = $this->deviceKey($request);

        return response()->json($this->instantPush->channelFor(
            $request->user(),
            $deviceKey,
            $request->userAgent()
        ));
    }

    public function instantInstalled(Request $request): JsonResponse
    {
        $deviceKey = $this->deviceKey($request);

        return response()->json($this->instantPush->confirmInstalled(
            $request->user(),
            $deviceKey,
            $request->userAgent()
        ));
    }

    public function instantLink(Request $request): JsonResponse
    {
        $deviceKey = $this->deviceKey($request);
        $user = $request->user();
        $channel = $this->instantPush->channelFor($user, $deviceKey, $request->userAgent());
        if (! $channel['installed']) {
            return response()->json([
                ...$channel,
                'message' => 'أكّد تثبيت تطبيق ntfy أولاً على هذا الجهاز.',
            ], 409);
        }

        return response()->json($this->instantPush->markLinked(
            $user,
            $deviceKey,
            $request->userAgent(),
            true
        ));
    }

    public function instantUnlink(Request $request): JsonResponse
    {
        $deviceKey = $this->deviceKey($request);

        return response()->json($this->instantPush->unlink($request->user(), $deviceKey));
    }

    public function instantTest(Request $request): JsonResponse
    {
        $deviceKey = $this->deviceKey($request);
        $user = $request->user();
        $user->loadMissing('camp:id,slug');
        $channel = $this->instantPush->channelFor($user, $deviceKey, $request->userAgent());
        if (! $channel['installed']) {
            return response()->json([
                ...$channel,
                'sent' => false,
                'ntfy' => false,
                'message' => 'أكّد تثبيت تطبيق ntfy أولاً على هذا الجهاز.',
            ], 409);
        }
        if (! $channel['linked']) {
            return response()->json([
                ...$channel,
                'sent' => false,
                'ntfy' => false,
                'message' => 'اربط هذا الجهاز بتطبيق ntfy أولاً.',
            ], 409);
        }

        $clickPath = '/family/notifications';
        if ($user->isSuper() && $user->camp_id === null) {
            $clickPath = '/super-admin';
        } elseif ($user->isAdmin()) {
            $clickPath = '/admin/dashboard';
        }
        $click = $this->instantPush->destinationUrl(
            $clickPath,
            [],
            $user->camp?->slug,
            $user
        );

        $ok = $this->instantPush->publish(
            $channel['topic'],
            'تَكافل',
            'هذا إشعار تجريبي من تطبيق ntfy.',
            $click
        );

        return response()->json([
            ...$channel,
            'sent' => $ok,
            'ntfy' => $ok,
            'message' => $ok
                ? 'تم إرسال إشعار تجريبي لهذا الجهاز.'
                : 'تعذر إرسال الإشعار التجريبي. تأكد إن تطبيق ntfy مربوط على هذا الجهاز.',
        ], $ok ? 200 : 502);
    }

    private function deviceKey(Request $request): string
    {
        $validated = $request->validate([
            'device_key' => ['required', 'string', 'min:8', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        return $this->instantPush->normalizeDeviceKey((string) $validated['device_key']);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ]);

        $ua = $request->header('user-agent');
        $endpoint = (string) $data['endpoint'];

        $sub = PushSubscription::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'endpoint_hash' => hash('sha256', $endpoint),
            ],
            [
                'endpoint' => $endpoint,
                'public_key' => (string) $data['keys']['p256dh'],
                'auth_token' => (string) $data['keys']['auth'],
                'content_encoding' => (string) ($data['contentEncoding'] ?? 'aesgcm'),
                'user_agent' => $ua ? mb_substr((string) $ua, 0, 255) : null,
            ]
        );

        return response()->json([
            'ok' => true,
            'subscription_id' => $sub->id,
        ]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', (string) $data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }
}

