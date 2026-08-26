<?php

namespace App\Models;

use App\Support\TenantCache;
use App\Traits\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_FAMILY_HEAD = 'family_head';

    protected static function booted(): void
    {
        $forgetFirstAdmin = function (User $user): void {
            if ($user->camp_id && ($user->role === self::ROLE_ADMIN || $user->getOriginal('role') === self::ROLE_ADMIN)) {
                TenantCache::forgetFirstAdmin((int) $user->camp_id);
            }
        };

        static::saved($forgetFirstAdmin);
        static::deleted($forgetFirstAdmin);
    }

    protected $fillable = [
        'national_id',
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_super',
        'camp_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super' => 'boolean',
        ];
    }

    public function family(): HasOne
    {
        return $this->hasOne(Family::class);
    }

    public function camp(): BelongsTo
    {
        return $this->belongsTo(Camp::class);
    }

    public function administeredDistributions(): HasMany
    {
        return $this->hasMany(Distribution::class, 'administered_by');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'admin_user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function isSuper(): bool
    {
        return (bool) $this->is_super;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isFamilyHead(): bool
    {
        return $this->role === self::ROLE_FAMILY_HEAD;
    }

    /**
     * مسؤول المخيم المعيَّن في camps.primary_admin_user_id، أو أقدم مسؤول إن لم يُحدَّد بعد.
     */
    public function isPrimaryCampAdmin(): bool
    {
        if (! $this->isAdmin() || $this->camp_id === null) {
            return false;
        }
        $camp = $this->relationLoaded('camp') ? $this->camp : $this->camp()->first();
        if (! $camp) {
            return false;
        }
        if ($camp->primary_admin_user_id !== null) {
            return (int) $camp->primary_admin_user_id === (int) $this->id;
        }

        $firstId = Cache::remember(
            TenantCache::firstAdminKey((int) $this->camp_id),
            TenantCache::ttl(TenantCache::TTL_MEDIUM),
            fn () => static::withoutGlobalScopes()
                ->where('camp_id', $this->camp_id)
                ->where('role', self::ROLE_ADMIN)
                ->orderBy('id')
                ->value('id')
        );

        return $firstId !== null && (int) $firstId === (int) $this->id;
    }

    /**
     * إضافة مسؤولين جدد: المسؤول الرئيسي للمخيم فقط، أو سوبر عام غير مرتبط بمخيم.
     */
    public function canAddCampAdmins(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }
        if ($this->isSuper() && $this->camp_id === null) {
            return true;
        }

        return $this->isPrimaryCampAdmin();
    }

    /**
     * حذف مسؤول آخر: المسؤول الرئيسي أو السوبر العام فقط، وليس المسؤول الرئيسي للمخيم كهدف.
     */
    public function canDeleteCampAdmin(User $target): bool
    {
        if ($target->isPrimaryCampAdmin()) {
            return false;
        }
        if ((int) $this->id === (int) $target->id) {
            return false;
        }
        if (! $this->isAdmin() || ! $target->isAdmin()) {
            return false;
        }
        if ($this->isSuper() && $this->camp_id === null) {
            return true;
        }

        return $this->canAddCampAdmins()
            && $this->camp_id !== null
            && $target->camp_id !== null
            && (int) $this->camp_id === (int) $target->camp_id;
    }

    /**
     * الرقم التسلسلي للدخول: 3 أرقام (id مع أصفار يساراً حتى 3 خانات).
     * أمثلة: 2 => 002، 10 => 010، 100 => 100
     */
    public static function defaultSerialFromId(int $id): string
    {
        return str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }
}
