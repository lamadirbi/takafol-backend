<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use App\Support\TenantCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(protected TenantManager $tenantManager) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantManager->clear();

        $slug = $this->resolveCampSlug($request);

        if ($slug !== null) {
            try {
                $camp = TenantCache::rememberActiveBySlug($slug);
                if ($camp) {
                    $this->tenantManager->setCurrentCamp($camp);
                }
            } catch (\Throwable) {
                // أثناء الاختبار أو قبل اكتمال المهاجرات لا نمنع الطلب.
            }
        }

        return $next($request);
    }

    private function resolveCampSlug(Request $request): ?string
    {
        $header = trim((string) $request->header('X-Camp-Slug', ''));
        if ($header !== '' && ! $this->isReservedSlug($header)) {
            return $header;
        }

        $host = strtolower((string) $request->getHost());
        if ($host === '' || $this->isIgnoredHost($host)) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        $candidate = $parts[0];
        if ($this->isReservedSlug($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isIgnoredHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (str_ends_with($host, '.sslip.io') || str_ends_with($host, '.nip.io')) {
            return true;
        }

        return in_array($host, ['localhost', '::1'], true);
    }

    private function isReservedSlug(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        return $slug === ''
            || ctype_digit($slug)
            || in_array($slug, ['www', 'localhost', 'api', 'admin', 'super-admin', 'takafol'], true);
    }
}
