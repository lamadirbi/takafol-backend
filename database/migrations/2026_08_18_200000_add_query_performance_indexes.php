<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->index(['camp_id', 'id'], 'families_camp_id_id_index');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->index(['announcement_id', 'created_at'], 'comments_announcement_created_at_index');
        });

        Schema::table('announcement_reactions', function (Blueprint $table) {
            $table->index(['announcement_id', 'type'], 'announcement_reactions_announcement_type_index');
        });

        Schema::table('camp_filter_records', function (Blueprint $table) {
            $table->index(['camp_id', 'created_at'], 'camp_filter_records_camp_created_at_index');
        });

        Schema::table('distributions', function (Blueprint $table) {
            $table->index(['camp_id', 'id'], 'distributions_camp_id_id_index');
        });

        Schema::table('change_requests', function (Blueprint $table) {
            $table->index(['camp_id', 'id'], 'change_requests_camp_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropIndex('families_camp_id_id_index');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_announcement_created_at_index');
        });

        Schema::table('announcement_reactions', function (Blueprint $table) {
            $table->dropIndex('announcement_reactions_announcement_type_index');
        });

        Schema::table('camp_filter_records', function (Blueprint $table) {
            $table->dropIndex('camp_filter_records_camp_created_at_index');
        });

        Schema::table('distributions', function (Blueprint $table) {
            $table->dropIndex('distributions_camp_id_id_index');
        });

        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropIndex('change_requests_camp_id_id_index');
        });
    }
};
