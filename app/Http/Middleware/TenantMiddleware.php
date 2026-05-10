<?php

namespace App\Http\Middleware;

use App\Models\Camp;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(protected TenantManager $tenantManager)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check for header (useful for API calls from subdomains to a shared backend)
        $slug = $request->header('X-Camp-Slug');

        // 2. Fallback to subdomain if no header
        if (! $slug) {
            $host = $request->getHost();
            $parts = explode('.', $host);
            if (count($parts) >= 2) {
                // If it's a subdomain, parts[0] is the slug
                // Note: This logic might need adjustment based on production TLD (e.g. .com.sa)
                $slug = $parts[0];
            }
        }

        if ($slug && ! in_array(strtolower((string) $slug), ['www'], true)) {
            $camp = Camp::where('slug', $slug)->where('is_active', true)->first();
            if ($camp) {
                $this->tenantManager->setCurrentCamp($camp);
            }
        }

        return $next($request);
    }
}
