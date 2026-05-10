<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distributions') && Schema::hasColumn('distributions', 'package_type_id')) {
            // change() lets Laravel handle platform differences (SQLite/MySQL).
            Schema::table('distributions', function (Blueprint $table) {
                $table->unsignedBigInteger('package_type_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('distributions') && Schema::hasColumn('distributions', 'package_type_id')) {
            // rollback may fail if rows currently contain NULL package_type_id.
            Schema::table('distributions', function (Blueprint $table) {
                $table->unsignedBigInteger('package_type_id')->nullable(false)->change();
            });
        }
    }
};

