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

    public function instantLink(Request $request): JsonResponse
    {
        return response()->json($this->instantPush->markLinked($request->user()));
    }

    public function instantUnlink(Request $request): JsonResponse
    {
        return response()->json($this->instantPush->unlink($request->user()));
    }

    public function instantTest(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('camp:id,slug');
        $channel = $this->instantPush->channelFor($user);
        if (! $channel['linked']) {
            return response()->json([
                ...$channel,
                'sent' => false,
                'ntfy' => false,
                'message' => 'اربطي تطبيق ntfy أولاً حتى يوصل الإشعار على التطبيق المثبّت.',
            ], 409);
        }

        $click = $this->instantPush->destinationUrl(
            $user->isAdmin() ? '/admin/dashboard' : '/family/notifications',
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
                ? 'تم إرسال إشعار تجريبي. لازم يطلع على تطبيق ntfy المثبّت، مش من المتصفح.'
                : 'تعذر إرسال الإشعار التجريبي. تأكدي إن تطبيق ntfy مربوط ثم أعيدي المحاولة.',
        ], $ok ? 200 : 502);
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

