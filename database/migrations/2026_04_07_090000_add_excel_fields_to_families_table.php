<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('head_gender', 16)->nullable()->after('head_name');
            $table->string('spouse_name', 255)->nullable()->after('social_status');
            $table->string('spouse_national_id', 32)->nullable()->after('spouse_name');
            $table->string('original_governorate', 64)->nullable()->after('file_status');
            $table->string('original_neighborhood', 64)->nullable()->after('original_governorate');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn([
                'head_gender',
                'spouse_name',
                'spouse_national_id',
                'original_governorate',
                'original_neighborhood',
            ]);
        });
    }
};

