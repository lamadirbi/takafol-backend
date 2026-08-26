<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
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

        return [
            ...$info,
            'topic' => $topic,
            'subscribe_url' => $info['base_url'].'/'.$topic,
            'deep_link' => 'ntfy://'.$host.'/'.$topic,
        ];
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

        $users = User::query()
            ->with('camp:id,slug')
            ->whereIn('id', $ids)
            ->whereNotNull('ntfy_topic')
            ->get(['id', 'ntfy_topic', 'camp_id', 'role']);

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

            return $response->successful();
        } catch (\Throwable) {
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

        if ($type === 'announcement' && $slug !== '') {
            $id = $data['announcement_id'] ?? null;
            $rel = '/'.$slug.'/news'.($id ? '#post-'.$id : '');
        } elseif ($type === 'distribution_pending' && $slug !== '') {
            $rel = '/'.$slug.'/family/notifications';
        } elseif ($type === 'change_request' && $slug !== '') {
            $rel = '/'.$slug.'/admin/change-requests';
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
