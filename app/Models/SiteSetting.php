<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

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
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /** @return array<string, string|null> */
    public static function allAsMap(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }
}
