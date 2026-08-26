<?php

namespace App\Services;

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
            'host' => $host,
            'base_url' => $base,
        ];
    }

    public function channelFor(User $user): array
    {
        $topic = $this->ensureTopic($user);
        $info = $this->appInfo();
        $host = $info['host'];
        $deep = 'ntfy://'.$host.'/'.$topic;
        $play = (string) $info['play_store_url'];
        $androidIntent = 'intent://'.$host.'/'.$topic
            .'#Intent;scheme=ntfy;package=io.heckel.ntfy;S.browser_fallback_url='
            .rawurlencode($play)
            .';end';

        return [
            ...$info,
            'topic' => $topic,
            'linked' => $user->ntfy_linked_at !== null,
            'subscribe_url' => $deep,
            'deep_link' => $deep,
            'android_intent' => $androidIntent,
        ];
    }

    public function markLinked(User $user): array
    {
        $this->ensureTopic($user);
        if ($user->ntfy_linked_at === null) {
            $user->ntfy_linked_at = now();
            $user->save();
        }

        return $this->channelFor($user->fresh() ?? $user);
    }

    public function unlink(User $user): array
    {
        $user->ntfy_topic = null;
        $user->ntfy_linked_at = null;
        $user->save();

        return $this->channelFor($user->fresh() ?? $user);
    }

    public function ensureTopic(User $user): string
    {
        $existing = trim((string) $user->ntfy_topic);
        if ($existing !== '') {
            return $existing;
        }

        do {
            $topic = 'takafol'.Str::lower(bin2hex(random_bytes(12)));
        } while (User::withoutGlobalScopes()->where('ntfy_topic', $topic)->exists());

        $user->ntfy_topic = $topic;
        $user->save();

        return $topic;
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
            ->whereNotNull('ntfy_topic')
            ->whereNotNull('ntfy_linked_at')
            ->get(['id', 'ntfy_topic', 'camp_id', 'role', 'is_super']);

        foreach ($users as $user) {
            $topic = trim((string) $user->ntfy_topic);
            if ($topic === '') {
                continue;
            }
            $click = $this->destinationUrl($url, $data, $user->camp?->slug, $user);
            $this->publish($topic, $title, $body, $click, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function publish(string $topic, string $title, string $body, ?string $url = null, array $data = []): bool
    {
        if (! $this->enabled() || $topic === '') {
            return false;
        }

        $payload = [
            'topic' => $topic,
            'title' => $title,
            'message' => $body,
            'tags' => ['bell'],
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
        } elseif ($type === 'change_request' && $slug !== '') {
            $rel = '/'.$slug.'/admin/change-requests';
        } elseif ($type === 'subscription_renewal_result' && $slug !== '') {
            $rel = '/'.$slug.'/admin/dashboard';
        } elseif ($slug !== '') {
            $mapped = [
                '/news' => '/'.$slug.'/news',
                '/dashboard' => '/'.$slug.'/family/notifications',
                '/family/dashboard' => '/'.$slug.'/family/dashboard',
                '/family/notifications' => '/'.$slug.'/family/notifications',
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
