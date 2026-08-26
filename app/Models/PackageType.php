<?php

namespace App\Models;

use App\Support\TenantCache;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageType extends Model
{
    use BelongsToTenant, HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => TenantCache::forgetPackageTypes());
        static::deleted(fn () => TenantCache::forgetPackageTypes());
    }

    protected $fillable = [
        'name',
        'description',
        'camp_id',
    ];

    public function distributions(): HasMany
    {
        return $this->hasMany(Distribution::class);
    }
}
