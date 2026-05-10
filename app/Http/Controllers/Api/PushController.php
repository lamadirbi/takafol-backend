<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(private readonly WebPushService $webPush)
    {
    }

    public function publicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => (string) env('VAPID_PUBLIC_KEY', ''),
        ]);
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

