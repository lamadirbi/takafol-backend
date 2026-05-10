<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * يحدّث حساب المسؤول التجريبي إن وُجد بدون بريد (قواعد قديمة) ليتوافق مع دخول الإدارة بالبريد + كلمة المرور.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = User::query()
            ->where('national_id', '1000000000')
            ->where('role', User::ROLE_ADMIN)
            ->first();

        if ($admin === null) {
            return;
        }

        if ($admin->email === null || $admin->email === '') {
            $admin->email = 'admin@taiba.local';
            $admin->password = 'AdminDemo123!';
            $admin->save();
        }
    }

    public function down(): void
    {
        // لا نعيد تعيين البريد تلقائياً
    }
};
