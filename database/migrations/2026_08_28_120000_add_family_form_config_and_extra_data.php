<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            $table->json('family_form_config')->nullable()->after('landing_page_data');
        });

        Schema::table('families', function (Blueprint $table) {
            $table->json('extra_data')->nullable()->after('original_neighborhood');
        });
    }

    public function down(): void
    {
        Schema::table('camps', function (Blueprint $table) {
            $table->dropColumn('family_form_config');
        });
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('extra_data');
        });
    }
};
