<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            $table->foreignId('primary_admin_user_id')
                ->nullable()
                ->after('landing_page_data')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            $table->dropForeign(['primary_admin_user_id']);
            $table->dropColumn('primary_admin_user_id');
        });
    }
};
