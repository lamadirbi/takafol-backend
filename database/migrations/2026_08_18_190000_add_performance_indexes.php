<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->index(['camp_id', 'national_id'], 'families_camp_national_id_index');
            $table->index(['camp_id', 'head_name'], 'families_camp_head_name_index');
            $table->index(['camp_id', 'social_status'], 'families_camp_social_status_index');
            $table->index(['camp_id', 'total_members'], 'families_camp_total_members_index');
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->index(['family_id', 'relationship'], 'family_members_family_relationship_index');
            $table->index(['family_id', 'gender'], 'family_members_family_gender_index');
            $table->index(['family_id', 'age'], 'family_members_family_age_index');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->index(['camp_id', 'created_at'], 'announcements_camp_created_at_index');
        });

        Schema::table('distributions', function (Blueprint $table) {
            $table->index(
                ['camp_filter_record_id', 'family_id', 'package_label'],
                'distributions_record_family_label_index'
            );
            $table->index(['family_id', 'status'], 'distributions_family_status_index');
        });

        Schema::table('change_requests', function (Blueprint $table) {
            $table->index(['camp_id', 'status'], 'change_requests_camp_status_index');
            $table->index(['family_id', 'status'], 'change_requests_family_status_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['camp_id', 'role'], 'users_camp_role_index');
        });

        Schema::table('camps', function (Blueprint $table) {
            $table->index(['is_active', 'slug'], 'camps_active_slug_index');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropIndex('families_camp_national_id_index');
            $table->dropIndex('families_camp_head_name_index');
            $table->dropIndex('families_camp_social_status_index');
            $table->dropIndex('families_camp_total_members_index');
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->dropIndex('family_members_family_relationship_index');
            $table->dropIndex('family_members_family_gender_index');
            $table->dropIndex('family_members_family_age_index');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_camp_created_at_index');
        });

        Schema::table('distributions', function (Blueprint $table) {
            $table->dropIndex('distributions_record_family_label_index');
            $table->dropIndex('distributions_family_status_index');
        });

        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropIndex('change_requests_camp_status_index');
            $table->dropIndex('change_requests_family_status_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_camp_role_index');
        });

        Schema::table('camps', function (Blueprint $table) {
            $table->dropIndex('camps_active_slug_index');
        });
    }
};
