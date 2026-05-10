<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Camp extends Model
{
    public function primaryAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_admin_user_id');
    }

    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'is_active',
        'landing_page_data',
        'primary_admin_user_id',
        'subscription_valid_until',
        'payment_notification_whatsapp',
        'subscription_notice_image_path',
    ];

    protected $casts = [
        'landing_page_data' => 'array',
        'is_active' => 'boolean',
        'subscription_valid_until' => 'date',
    ];

    public function graceDays(): int
    {
        return max(0, (int) config('subscription.grace_days_after_expiry', 15));
    }

    /**
     * آخر يوم من الاشتراك المدفوع (بداية اليوم).
     */
    public function subscriptionExpiryDay(): ?Carbon
    {
        if ($this->subscription_valid_until === null) {
            return null;
        }

        return Carbon::parse($this->subscription_valid_until)->startOfDay();
    }

    /**
     * آخر يوم من فترة السماح (انتهاء السماح = اليوم التالي يُحجب الدخول).
     */
    public function subscriptionHardLockDay(): ?Carbon
    {
        $exp = $this->subscriptionExpiryDay();
        if ($exp === null) {
            return null;
        }

        return $exp->copy()->addDays($this->graceDays());
    }

    /**
     * لم يُحدَّد تاريخ اشتراك → لا حظر.
     */
    public function familiesHardBlocked(): bool
    {
        if ($this->subscription_valid_until === null) {
            return false;
        }

        $hard = $this->subscriptionHardLockDay();
        if ($hard === null) {
            return false;
        }

        return Carbon::today()->gt($hard);
    }

    /**
     * بعد انتهاء تاريخ الاشتراك وما دام ضمن أيام السماح.
     */
    public function familiesInGracePeriod(): bool
    {
        if ($this->subscription_valid_until === null) {
            return false;
        }

        $exp = $this->subscriptionExpiryDay();
        $hard = $this->subscriptionHardLockDay();
        if ($exp === null || $hard === null) {
            return false;
        }

        $today = Carbon::today();

        return $today->gt($exp) && $today->lte($hard);
    }

    /**
     * دخول العائلات (تسجيل الدخول + لوحة أساسية) مسموح ما لم يُتجاوز آخر يوم سماح.
     */
    public function familiesAccessAllowed(): bool
    {
        if ($this->subscription_valid_until === null) {
            return true;
        }

        return ! $this->familiesHardBlocked();
    }

    public function subscriptionNoticeImageUrl(): ?string
    {
        if ($this->subscription_notice_image_path === null || $this->subscription_notice_image_path === '') {
            return null;
        }

        return asset('storage/'.$this->subscription_notice_image_path);
    }

    /**
     * بيانات للوحة إدارة المخيم: العداد والحالة.
     *
     * @return array<string, mixed>
     */
    public function subscriptionAdminMeta(): array
    {
        $grace = $this->graceDays();
        $amount = (int) config('subscription.monthly_amount_ils', 15);

        if ($this->subscription_valid_until === null) {
            return [
                'status' => 'unlimited',
                'grace_days' => $grace,
                'monthly_amount_ils' => $amount,
                'message' => 'لم يُحدَّد تاريخ انتهاء الاشتراك؛ لن يُطبَّق حظر على العائلات حتى تضع تاريخاً من إدارة المنصة.',
            ];
        }

        $today = Carbon::today();
        $expiryDay = $this->subscriptionExpiryDay();
        $hardLockDay = $this->subscriptionHardLockDay();

        if ($expiryDay === null || $hardLockDay === null) {
            return [
                'status' => 'unlimited',
                'grace_days' => $grace,
                'monthly_amount_ils' => $amount,
            ];
        }

        if ($today->lte($expiryDay)) {
            return [
                'status' => 'active',
                'days_until_expiry' => (int) $today->diffInDays($expiryDay, false),
                'valid_until' => $expiryDay->toDateString(),
                'hard_lock_at' => $hardLockDay->toDateString(),
                'grace_days' => $grace,
                'monthly_amount_ils' => $amount,
            ];
        }

        if ($today->lte($hardLockDay)) {
            return [
                'status' => 'grace',
                'days_until_hard_lock' => (int) $today->diffInDays($hardLockDay, false),
                'valid_until' => $expiryDay->toDateString(),
                'hard_lock_at' => $hardLockDay->toDateString(),
                'grace_days' => $grace,
                'monthly_amount_ils' => $amount,
                'message' => 'فترة سماح: العائلات تدخل لكن المميزات (طلبات التعديل، التفاعل، إشعارات…) معطّلة حتى يُسدَّد اشتراك '.$amount.' شيكل شهرياً.',
            ];
        }

        return [
            'status' => 'locked',
            'valid_until' => $expiryDay->toDateString(),
            'hard_lock_at' => $hardLockDay->toDateString(),
            'grace_days' => $grace,
            'monthly_amount_ils' => $amount,
            'message' => 'انتهى الاشتراك وفترة السماح؛ العائلات محجوبة حتى يُحدَّد تاريخ جديد من إدارة المنصة.',
        ];
    }
}
