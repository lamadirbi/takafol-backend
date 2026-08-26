<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function vapidPublicKey(): string
    {
        return trim((string) config('webpush.vapid_public', ''));
    }

    public function isConfigured(): bool
    {
        return $this->vapidPublicKey() !== '' && trim((string) config('webpush.vapid_private', '')) !== '';
    }

    private function makeWebPush(): ?WebPush
    {
        $publicKey = $this->vapidPublicKey();
        $privateKey = trim((string) config('webpush.vapid_private', ''));
        if ($publicKey === '' || $privateKey === '') {
            return null;
        }

        try {
            $subject = trim((string) config('webpush.vapid_subject', '')) ?: (string) config('app.url');
            if ($subject === '') {
                $subject = 'mailto:admin@example.com';
            }

            return new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(User $user, string $title, string $body, ?string $url = null, array $data = []): void
    {
        $this->sendInstant([(int) $user->id], $title, $body, $url, $data);

        try {
            $subs = $user->pushSubscriptions()->get();
            if ($subs->isEmpty()) {
                return;
            }

            $webPush = $this->makeWebPush();
            if (! $webPush) {
                return;
            }
            foreach ($subs as $sub) {
                $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
            }
            $this->flushAndCleanup($webPush, $user->id);
        } catch (\Throwable) {
            // لا نكسر العملية الأساسية بسبب إعداد Push غير مكتمل.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAllFamilyHeadsAfterResponse(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $this->dispatchRole(User::ROLE_FAMILY_HEAD, $title, $body, $url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAllFamilyHeads(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $this->notifyRole(User::ROLE_FAMILY_HEAD, $title, $body, $url, $data);
    }

    /**
     * إشعار الإدارة العليا للمنصة (بدون مخيم).
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyGlobalSuperAdmins(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $ids = User::withoutGlobalScopes()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_super', true)
            ->whereNull('camp_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->deliverToUserIds($ids, $title, $body, $url, $data);
    }

    /**
     * إشعار مسؤولي مخيم محدد فقط.
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyCampAdmins(int $campId, string $title, string $body, ?string $url = null, array $data = []): void
    {
        if ($campId < 1) {
            return;
        }

        $ids = User::withoutGlobalScopes()
            ->where('role', User::ROLE_ADMIN)
            ->where('camp_id', $campId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->deliverToUserIds($ids, $title, $body, $url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAllAdminsAfterResponse(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $this->dispatchRole(User::ROLE_ADMIN, $title, $body, $url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyAllAdmins(string $title, string $body, ?string $url = null, array $data = []): void
    {
        $this->notifyRole(User::ROLE_ADMIN, $title, $body, $url, $data);
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

        $this->sendInstant($ids, $title, $body, $url, $data);
        $this->deliverToUserIds($ids, $title, $body, $url, $data, false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyRole(string $role, string $title, string $body, ?string $url, array $data, bool $includeInstant = true): void
    {
        $roleIds = User::query()->where('role', $role)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($includeInstant) {
            $this->sendInstant($roleIds, $title, $body, $url, $data);
        }

        try {
            $webPush = $this->makeWebPush();
            if (! $webPush) {
                return;
            }

            $userIdsTouched = [];
            $userIds = User::query()->where('role', $role)->select('id');

            PushSubscription::query()
                ->whereIn('user_id', $userIds)
                ->chunkById(200, function ($subs) use ($webPush, $title, $body, $url, $data, &$userIdsTouched) {
                    foreach ($subs as $sub) {
                        $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
                        $userIdsTouched[(int) $sub->user_id] = true;
                    }
                });

            $this->flushAndCleanup($webPush, array_keys($userIdsTouched));
        } catch (\Throwable) {
            // لا نكسر العملية الأساسية بسبب إعداد Push غير مكتمل.
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function deliverToUserIds(array $userIds, string $title, string $body, ?string $url, array $data, bool $includeInstant = true): void
    {
        if ($includeInstant) {
            $this->sendInstant($userIds, $title, $body, $url, $data);
        }

        try {
            $webPush = $this->makeWebPush();
            if (! $webPush) {
                return;
            }

            PushSubscription::query()
                ->whereIn('user_id', $userIds)
                ->chunkById(200, function ($subs) use ($webPush, $title, $body, $url, $data) {
                    foreach ($subs as $sub) {
                        $this->queueNotification($webPush, $sub, $title, $body, $url, $data);
                    }
                });

            $this->flushAndCleanup($webPush, $userIds);
        } catch (\Throwable) {
            // لا نكسر العملية الأساسية بسبب إعداد Push غير مكتمل.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatchRole(string $role, string $title, string $body, ?string $url, array $data): void
    {
        $roleIds = User::query()->where('role', $role)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->sendInstant($roleIds, $title, $body, $url, $data);
        $this->notifyRole($role, $title, $body, $url, $data, false);
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private function sendInstant(array $userIds, string $title, string $body, ?string $url, array $data): void
    {
        try {
            app(InstantPushService::class)->notifyUserIds($userIds, $title, $body, $url, $data);
        } catch (\Throwable) {
            // لا نكسر العملية الأساسية إذا تعذّر ntfy.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function queueNotification(WebPush $webPush, PushSubscription $sub, string $title, string $body, ?string $url, array $data): void
    {
        $click = app(InstantPushService::class)->destinationUrl($url, $data) ?: $url;
        $payload = [
            'title' => $title,
            'body' => $body,
            'url' => $click,
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
