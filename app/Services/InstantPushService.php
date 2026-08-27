<?php

namespace App\Services;

use App\Models\NtfyDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * إشعارات فورية عبر تطبيق ntfy الجاهز (Google Play / App Store)
 * بدل نظام إشعارات المتصفح غير الموثوق.
 */
class InstantPushService
{
    public function appInfo(): array
    {
        $base = $this->baseUrl();
        $host = parse_url($base, PHP_URL_HOST) ?: 'ntfy.sh';

        return [
            'app' => 'ntfy',
            'app_name' => 'ntfy',
            'play_store_url' => (string) config('ntfy.play_store_url'),
            'app_store_url' => (string) config('ntfy.app_store_url'),
            'android_install_intent' => 'intent://details?id=io.heckel.ntfy#Intent;scheme=market;package=com.android.vending;S.browser_fallback_url='
                .rawurlencode((string) config('ntfy.play_store_url'))
                .';end',
            'host' => $host,
            'base_url' => $base,
        ];
    }

    public function channelFor(User $user, string $deviceKey, ?string $userAgent = null): array
    {
        $device = $this->deviceFor($user, $deviceKey, $userAgent);

        return $this->channelPayload($user, $device);
    }

    public function confirmInstalled(User $user, string $deviceKey, ?string $userAgent = null): array
    {
        $device = $this->deviceFor($user, $deviceKey, $userAgent);
        if ($device->installed_at === null) {
            $device->installed_at = now();
            $device->save();
        }

        return $this->channelPayload($user, $device);
    }

    public function markLinked(User $user, string $deviceKey = 'test-device', ?string $userAgent = null, bool $requireInstalled = false): array
    {
        $device = $this->deviceFor($user, $deviceKey, $userAgent);
        if ($requireInstalled && $device->installed_at === null) {
            return [
                ...$this->channelPayload($user, $device),
                'error' => 'install_required',
            ];
        }
        if ($device->installed_at === null) {
            $device->installed_at = now();
        }
        if ($device->linked_at === null) {
            $device->linked_at = now();
        }
        $device->save();
        $this->forgetLegacyUserChannel($user);

        return $this->channelPayload($user, $device);
    }

    public function unlink(User $user, string $deviceKey = 'test-device'): array
    {
        $device = $this->deviceFor($user, $deviceKey);
        $device->linked_at = null;
        $device->topic = $this->freshTopic();
        $device->save();

        return $this->channelPayload($user, $device);
    }

    public function ensureTopic(User $user, string $deviceKey = 'test-device'): string
    {
        return $this->deviceFor($user, $deviceKey)->topic;
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function notifyUserIds(array $userIds, string $title, string $body, ?string $url = null, array $data = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === []) {
            return;
        }

        $users = User::withoutGlobalScopes()
            ->with('camp:id,slug')
            ->whereIn('id', $ids)
            ->get(['id', 'ntfy_topic', 'ntfy_linked_at', 'camp_id', 'role', 'is_super'])
            ->keyBy('id');

        $linkedUserIds = [];
        $devices = NtfyDevice::query()
            ->whereIn('user_id', $ids)
            ->whereNotNull('linked_at')
            ->whereNotNull('topic')
            ->get(['user_id', 'topic']);

        foreach ($devices as $device) {
            $user = $users->get((int) $device->user_id);
            if (! $user) {
                continue;
            }
            $topic = trim((string) $device->topic);
            if ($topic === '') {
                continue;
            }
            $linkedUserIds[(int) $user->id] = true;
            $click = $this->destinationUrl($url, $data, $user->camp?->slug, $user);
            $this->publish($topic, $title, $body, $click, $data);
        }

        foreach ($users as $user) {
            if (isset($linkedUserIds[(int) $user->id])) {
                continue;
            }
            if ($user->ntfy_linked_at === null) {
                continue;
            }
            $topic = trim((string) $user->ntfy_topic);
            if ($topic === '') {
                continue;
            }
            $click = $this->destinationUrl($url, $data, $user->camp?->slug, $user);
            $this->publish($topic, $title, $body, $click, $data);
        }
    }

    private function deviceFor(User $user, string $deviceKey, ?string $userAgent = null): NtfyDevice
    {
        $key = $this->normalizeDeviceKey($deviceKey);
        $device = NtfyDevice::query()
            ->where('user_id', $user->id)
            ->where('device_key', $key)
            ->first();

        if ($device) {
            if ($userAgent && $device->user_agent !== $userAgent) {
                $device->user_agent = mb_substr($userAgent, 0, 255);
                $device->save();
            }

            return $device;
        }

        $device = new NtfyDevice([
            'user_id' => $user->id,
            'device_key' => $key,
            'topic' => $this->freshTopic(),
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
        ]);
        $device->save();

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPayload(User $user, NtfyDevice $device): array
    {
        $info = $this->appInfo();
        $host = $info['host'];
        $topic = trim((string) $device->topic);
        $deep = $topic !== '' ? 'ntfy://'.$host.'/'.$topic : '';
        $androidIntent = $topic !== ''
            ? 'intent://'.$host.'/'.$topic.'#Intent;scheme=ntfy;package=io.heckel.ntfy;end'
            : '';

        return [
            ...$info,
            'device_key' => $device->device_key,
            'topic' => $topic,
            'installed' => $device->installed_at !== null,
            'linked' => $device->linked_at !== null,
            'subscribe_url' => $deep,
            'deep_link' => $deep,
            'android_intent' => $androidIntent,
        ];
    }

    private function forgetLegacyUserChannel(User $user): void
    {
        if ($user->ntfy_linked_at === null && trim((string) $user->ntfy_topic) === '') {
            return;
        }
        $user->ntfy_topic = null;
        $user->ntfy_linked_at = null;
        $user->save();
    }

    private function freshTopic(): string
    {
        do {
            $topic = 'takafol'.Str::lower(bin2hex(random_bytes(12)));
        } while (
            NtfyDevice::query()->where('topic', $topic)->exists()
            || User::withoutGlobalScopes()->where('ntfy_topic', $topic)->exists()
        );

        return $topic;
    }

    public function normalizeDeviceKey(string $deviceKey): string
    {
        $key = trim($deviceKey);
        if (strlen($key) < 8) {
            throw new \InvalidArgumentException('device_key');
        }

        return mb_substr($key, 0, 80);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function publish(string $topic, string $title, string $body, ?string $url = null, array $data = [], int $priority = 4): bool
    {
        if (! $this->enabled() || $topic === '') {
            return false;
        }

        $payload = [
            'topic' => $topic,
            'title' => $title,
            'message' => $body,
            'tags' => ['bell'],
            'priority' => max(1, min(5, $priority)),
        ];
        $click = $this->normalizeAbsoluteUrl($url);
        if ($click) {
            $payload['click'] = $click;
            $payload['actions'] = [
                [
                    'action' => 'view',
                    'label' => 'فتح',
                    'url' => $click,
                    'clear' => true,
                ],
            ];
        }

        try {
            $request = Http::timeout(8)->acceptJson();
            $token = trim((string) config('ntfy.token'));
            if ($token !== '') {
                $request = $request->withToken($token);
            }
            $response = $request->post($this->baseUrl(), $payload);
            if ($response->successful()) {
                return true;
            }
            Log::warning('ntfy publish failed', [
                'topic' => $topic,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('ntfy publish exception', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * رابط كامل يفتح الموقع على الصفحة المطلوبة عند الضغط على الإشعار.
     *
     * @param  array<string, mixed>  $data
     */
    public function destinationUrl(?string $path, array $data = [], ?string $campSlug = null, ?User $user = null): ?string
    {
        if (is_string($path) && preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $slug = trim((string) ($campSlug ?: ''));
        if ($slug === '' && $user?->relationLoaded('camp')) {
            $slug = trim((string) ($user->camp?->slug ?: ''));
        }
        if ($slug === '' && \Illuminate\Support\Facades\App::has('current_camp')) {
            $camp = \Illuminate\Support\Facades\App::get('current_camp');
            $slug = is_object($camp) ? trim((string) ($camp->slug ?? '')) : '';
        }

        $type = (string) ($data['type'] ?? '');
        $rel = '/'.ltrim((string) ($path ?: ''), '/');
        $isGlobalSuper = $user && $user->isSuper() && $user->camp_id === null;

        if ($isGlobalSuper) {
            if ($type === 'camp_registration') {
                $rel = '/super-admin/requests';
            } elseif ($type === 'subscription_renewal') {
                $rel = '/super-admin/renewals';
            } elseif (in_array($type, ['subscription_expiring', 'subscription_grace', 'subscription_locked'], true)) {
                $campId = (int) ($data['camp_id'] ?? 0);
                $rel = $campId > 0 ? '/super-admin/camps/'.$campId : '/super-admin/camps';
            } elseif (! str_starts_with(ltrim($rel, '/'), 'super-admin')) {
                $rel = '/super-admin';
            }

            return $this->normalizeAbsoluteUrl($rel);
        }

        if ($type === 'announcement' && $slug !== '') {
            $id = $data['announcement_id'] ?? null;
            $rel = '/'.$slug.'/news'.($id ? '#post-'.$id : '');
        } elseif ($type === 'distribution_pending' && $slug !== '') {
            $rel = '/'.$slug.'/family/notifications';
        } elseif ($type === 'distribution_cancelled' && $slug !== '') {
            $rel = '/'.$slug.'/family/notifications';
        } elseif ($type === 'change_request' && $slug !== '') {
            $rel = '/'.$slug.'/admin/change-requests';
        } elseif ($type === 'change_request_review' && $slug !== '') {
            $rel = '/'.$slug.'/family/change-requests';
        } elseif (in_array($type, ['subscription_expiring', 'subscription_renewal_result'], true) && $slug !== '') {
            $rel = '/'.$slug.'/admin/dashboard';
        } elseif ($slug !== '') {
            $mapped = [
                '/news' => '/'.$slug.'/news',
                '/dashboard' => '/'.$slug.'/family/notifications',
                '/family/dashboard' => '/'.$slug.'/family/dashboard',
                '/family/notifications' => '/'.$slug.'/family/notifications',
                '/family/change-requests' => '/'.$slug.'/family/change-requests',
                '/admin/change-requests' => '/'.$slug.'/admin/change-requests',
                '/admin/dashboard' => '/'.$slug.'/admin/dashboard',
            ];
            if (isset($mapped[$rel]) || isset($mapped['/'.ltrim($rel, '/')])) {
                $rel = $mapped[$rel] ?? $mapped['/'.ltrim($rel, '/')];
            } elseif (! str_starts_with(ltrim($rel, '/'), $slug.'/')) {
                $rel = '/'.$slug.($rel === '/' ? '' : $rel);
            }
        }

        if ($user && $user->isAdmin() && str_contains($rel, '/family/')) {
            $rel = $slug !== '' ? '/'.$slug.'/admin/dashboard' : '/super-admin';
        }

        return $this->normalizeAbsoluteUrl($rel);
    }

    private function normalizeAbsoluteUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $frontend = rtrim((string) config('ntfy.frontend_url', 'http://localhost:3000'), '/');
        if ($frontend === '') {
            $frontend = 'http://localhost:3000';
        }

        return $frontend.'/'.ltrim($url, '/');
    }

    private function enabled(): bool
    {
        return (bool) config('ntfy.enabled', true);
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('ntfy.base_url', 'https://ntfy.sh'), '/');

        return $base !== '' ? $base : 'https://ntfy.sh';
    }
}
