<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('head_name');
            $table->string('national_id', 32);
            $table->string('phone', 32)->nullable();
            $table->string('social_status', 64)->nullable();
            $table->string('financial_status', 64)->nullable();
            $table->unsignedSmallInteger('total_members')->default(0);
            $table->string('file_status', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('relationship', 64)->nullable();
            $table->string('gender', 16)->default('unknown');
            $table->timestamps();
        });

        Schema::create('package_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_type_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('administered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('distributions');
        Schema::dropIfExists('package_types');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('families');
    }
};
