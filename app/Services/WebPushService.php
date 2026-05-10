<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private function makeWebPush(): WebPush
    {
        $subject = config('app.url') ?: (string) env('VAPID_SUBJECT', 'mailto:admin@example.com');

        return new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => (string) env('VAPID_PUBLIC_KEY', ''),
                'privateKey' => (string) env('VAPID_PRIVATE_KEY', ''),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(User $user, string $title, string $body, ?string $url = null, array $data = []): void
    {
        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return;
        }

        $webPush = $this->makeWebPush();
        foreach ($subs as $sub) {
            $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
        }
        $this->flushAndCleanup($webPush, $user->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAllFamilyHeads(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $webPush = $this->makeWebPush();

        $q = PushSubscription::query()
            ->whereHas('user', fn ($u) => $u->where('role', User::ROLE_FAMILY_HEAD))
            ->with('user:id,role');

        $userIdsTouched = [];
        foreach ($q->cursor() as $sub) {
            /** @var PushSubscription $sub */
            $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
            $userIdsTouched[(int) $sub->user_id] = true;
        }

        $this->flushAndCleanup($webPush, array_keys($userIdsTouched));
    }

    /**
     * إشعار جميع المستخدمين بدور الإدارة (مشتركي Push).
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyAllAdmins(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $webPush = $this->makeWebPush();

        $q = PushSubscription::query()
            ->whereHas('user', fn ($u) => $u->where('role', User::ROLE_ADMIN))
            ->with('user:id,role');

        $userIdsTouched = [];
        foreach ($q->cursor() as $sub) {
            /** @var PushSubscription $sub */
            $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
            $userIdsTouched[(int) $sub->user_id] = true;
        }

        $this->flushAndCleanup($webPush, array_keys($userIdsTouched));
    }

    /**
     * @param  iterable<int, int>  $familyUserIds
     * @param  array<string, mixed>  $data
     */
    public function notifyFamilyHeadsByUserIds(iterable $familyUserIds, string $title, string $body, ?string $url = null, array $data = []): void
    {
        $ids = collect($familyUserIds)->filter()->unique()->values()->all();
        if (! count($ids)) {
            return;
        }

        $webPush = $this->makeWebPush();

        $subs = PushSubscription::query()
            ->whereIn('user_id', $ids)
            ->get();

        foreach ($subs as $sub) {
            $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
        }

        $this->flushAndCleanup($webPush, $ids);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function queueNotification(WebPush $webPush, PushSubscription $sub, string $title, string $body, ?string $url, array $data): void
    {
        $payload = [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'data' => $data,
        ];

        $subscription = Subscription::create([
            'endpoint' => $sub->endpoint,
            'publicKey' => $sub->public_key,
            'authToken' => $sub->auth_token,
            'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
        ]);

        $webPush->queueNotification($subscription, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  int|list<int>  $userIds
     */
    private function flushAndCleanup(WebPush $webPush, int|array $userIds): void
    {
        $ids = is_array($userIds) ? $userIds : [$userIds];
        $badEndpoints = [];

        try {
            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    $endpoint = (string) $report->getRequest()?->getUri();
                    if ($endpoint !== '') {
                        $badEndpoints[] = $endpoint;
                    }
                }
            }
        } catch (\Throwable) {
            // لا نكسر العملية الأساسية (نشر خبر/إنشاء طرد) بسبب push.
        }

        if (count($badEndpoints)) {
            PushSubscription::query()
                ->whereIn('user_id', $ids)
                ->whereIn('endpoint', array_values(array_unique($badEndpoints)))
                ->delete();
        }
    }
}

