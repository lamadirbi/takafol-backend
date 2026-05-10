<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->foreignId('camp_filter_record_id')
                ->nullable()
                ->after('package_type_id')
                ->constrained('camp_filter_records')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('distributions', function (Blueprint $table) {
            $table->dropForeign(['camp_filter_record_id']);
            $table->dropColumn('camp_filter_record_id');
        });
    }
};
