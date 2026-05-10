<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'users',
            'families',
            'family_members',
            'package_types',
            'announcements',
            'distributions',
            'site_settings',
            'change_requests',
            'push_subscriptions',
            'camp_filter_records',
            'comments',
            'announcement_reactions',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                // camp_id is nullable initially to allow migrating existing data
                $table->foreignId('camp_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'users',
            'families',
            'family_members',
            'package_types',
            'announcements',
            'distributions',
            'site_settings',
            'change_requests',
            'push_subscriptions',
            'camp_filter_records',
            'comments',
            'announcement_reactions',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Drop foreign key with standard naming convention
                $table->dropForeign([$tableName . '_camp_id_foreign']);
                $table->dropColumn('camp_id');
            });
        }
    }
};
