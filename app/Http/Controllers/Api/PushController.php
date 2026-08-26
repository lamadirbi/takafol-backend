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
        return response()->json($this->instantPush->channelFor($request->user()));
    }

    public function instantTest(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('camp:id,slug');
        $channel = $this->instantPush->channelFor($user);
        $click = $this->instantPush->destinationUrl(
            $user->isAdmin() ? '/admin/dashboard' : '/family/notifications',
            [],
            $user->camp?->slug,
            $user
        );

        $ntfyOk = $this->instantPush->publish(
            $channel['topic'],
            'تَكافل',
            'هذا إشعار تجريبي. اضغط عليه لفتح الموقع.',
            $click
        );

        $this->webPush->deliverToUserIds(
            [(int) $user->id],
            'تَكافل',
            'هذا إشعار تجريبي. اضغط عليه لفتح الموقع.',
            $click,
            ['type' => 'push_test'],
            false,
        );

        $hasBrowser = $user->pushSubscriptions()->exists();
        $sent = $ntfyOk || $hasBrowser;

        return response()->json([
            ...$channel,
            'sent' => $sent,
            'browser' => $hasBrowser,
            'ntfy' => $ntfyOk,
            'message' => $sent
                ? 'تم إرسال إشعار تجريبي. يفترض يطلع على هذا الجهاز إذا الإشعارات مفعّلة.'
                : 'تعذر إرسال الإشعار التجريبي. فعّلي الإشعارات من الزر أعلاه ثم أعيدي المحاولة.',
        ], $sent ? 200 : 502);
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

