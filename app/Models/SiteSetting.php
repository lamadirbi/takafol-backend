<?php

namespace App\Models;

use App\Support\TenantCache;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'site_settings';

    protected $fillable = [
        'key',
        'value',
        'camp_id',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public static function putValue(string $key, ?string $value): void
    {
        $campId = App::has('current_camp_id') ? App::get('current_camp_id') : null;

        static::withoutGlobalScopes()->updateOrCreate(
            [
                'key' => $key,
                'camp_id' => $campId,
            ],
            ['value' => $value]
        );

        TenantCache::forgetSiteSettings($campId !== null ? (int) $campId : 0);
    }

    /** @return array<string, string|null> */
    public static function allAsMap(): array
    {
        return Cache::remember(
            TenantCache::siteSettingsKey(),
            TenantCache::ttl(TenantCache::TTL_LONG),
            fn () => static::query()->pluck('value', 'key')->all()
        );
    }
}
