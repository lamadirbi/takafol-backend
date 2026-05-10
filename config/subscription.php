<?php

return [

    /*
    | أيام السماح بعد انتهاء تاريخ subscription_valid_until دون تجديد:
    | خلالها يبقى دخول العائلات ممكناً لكن بدون مميزات (تعليقات، طلبات تعديل، إشعارات دفع، إلخ).
    | بعدها يُحجب الدخول بالكامل حتى يُحدَّث التاريخ.
    */
    // حسب المطلوب الحالي: عند انتهاء الاشتراك تُحجب الخدمات مباشرة (بدون سماح)
    'grace_days_after_expiry' => (int) env('SUBSCRIPTION_GRACE_DAYS', 0),

    /** قيمة الاشتراك الشهري (عرض فقط في الواجهة) */
    'monthly_amount_ils' => (int) env('SUBSCRIPTION_MONTHLY_AMOUNT_ILS', 15),

    /** مدة التجربة المجانية عند إنشاء المخيم لأول مرة (بالأيام) */
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),

    /** مدة التجديد الشهري المعتمد (بالأيام) */
    'renewal_days' => (int) env('SUBSCRIPTION_RENEWAL_DAYS', 30),
];
