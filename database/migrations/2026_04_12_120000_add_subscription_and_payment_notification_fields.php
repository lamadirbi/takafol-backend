<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            $table->date('subscription_valid_until')->nullable();
            $table->string('payment_notification_whatsapp', 32)->nullable();
        });

        Schema::table('camp_registration_requests', function (Blueprint $table) {
            $table->string('payment_notification_whatsapp', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            $table->dropColumn(['subscription_valid_until', 'payment_notification_whatsapp']);
        });

        Schema::table('camp_registration_requests', function (Blueprint $table) {
            $table->dropColumn('payment_notification_whatsapp');
        });
    }
};
