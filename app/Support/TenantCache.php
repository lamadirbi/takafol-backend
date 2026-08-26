<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Camp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

class TenantCache
{
    public const TTL_SHORT = 120;

    public const TTL_MEDIUM = 300;

    public const TTL_LONG = 600;

    public static function ttl(int $seconds): Carbon
    {
        return now()->addSeconds($seconds);
    }

    public static function publicCampsKey(): string
    {
        return 'camps:public_index';
    }

    public static function activeSlugKey(string $slug): string
    {
        return 'camps:active:slug:'.$slug;
    }

    public static function siteSettingsKey(?int $campId = null): string
    {
        $id = $campId ?? (App::has('current_camp_id') ? (int) App::get('current_camp_id') : 0);

        return 'site_settings:'.$id;
    }

    public static function packageTypesKey(?int $campId = null): string
    {
        $id = $campId ?? (App::has('current_camp_id') ? (int) App::get('current_camp_id') : 0);

        return 'package_types:'.$id;
    }

    public static function firstAdminKey(int $campId): string
    {
        return 'camps:'.$campId.':first_admin_id';
    }

    public static function rememberActiveBySlug(string $slug): ?Camp
    {
        $key = self::activeSlugKey($slug);
        $cached = Cache::get($key);
        if ($cached instanceof Camp) {
            return $cached;
        }

        $camp = Camp::query()->where('slug', $slug)->where('is_active', true)->first();
        if ($camp) {
            Cache::put($key, $camp, self::ttl(self::TTL_MEDIUM));
        }

        return $camp;
    }

    public static function forgetCamp(?Camp $camp, ?string $previousSlug = null): void
    {
        Cache::forget(self::publicCampsKey());

        if ($camp !== null) {
            Cache::forget(self::activeSlugKey($camp->slug));
            Cache::forget(self::firstAdminKey((int) $camp->id));
            Cache::forget(self::siteSettingsKey((int) $camp->id));
            Cache::forget(self::packageTypesKey((int) $camp->id));
        }

        if ($previousSlug !== null && $previousSlug !== '') {
            Cache::forget(self::activeSlugKey($previousSlug));
        }
    }

    public static function forgetSiteSettings(?int $campId = null): void
    {
        Cache::forget(self::siteSettingsKey($campId));
    }

    public static function forgetPackageTypes(?int $campId = null): void
    {
        Cache::forget(self::packageTypesKey($campId));
    }

    public static function forgetFirstAdmin(int $campId): void
    {
        Cache::forget(self::firstAdminKey($campId));
    }
}
