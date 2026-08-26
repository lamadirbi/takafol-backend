<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('device_tokens');
    }

    public function down(): void
    {
        // جدول مؤقت من محاولة تطبيق أصلي — لا نعيد إنشاءه.
    }
};
