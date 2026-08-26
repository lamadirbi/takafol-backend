<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * تحميل صاحب التوكن بدون نطاق المخيم حتى لا يُرفض التوكن الصالح
 * عندما يكون X-Camp-Slug مفعّلاً (خصوصاً السوبر أدمن أو بعد تسجيل الدخول مباشرة).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScopes();
    }
}
