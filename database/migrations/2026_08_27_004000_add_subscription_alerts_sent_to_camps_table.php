<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            if (! Schema::hasColumn('camps', 'subscription_alerts_sent')) {
                $table->json('subscription_alerts_sent')->nullable()->after('subscription_valid_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            if (Schema::hasColumn('camps', 'subscription_alerts_sent')) {
                $table->dropColumn('subscription_alerts_sent');
            }
        });
    }
};
