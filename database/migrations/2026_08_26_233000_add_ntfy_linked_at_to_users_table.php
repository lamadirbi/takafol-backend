<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'ntfy_linked_at')) {
                $table->timestamp('ntfy_linked_at')->nullable()->after('ntfy_topic');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'ntfy_linked_at')) {
                $table->dropColumn('ntfy_linked_at');
            }
        });
    }
};
