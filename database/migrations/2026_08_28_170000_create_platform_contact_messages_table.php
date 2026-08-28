<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('whatsapp_phone', 32);
            $table->string('camp_name', 255)->nullable();
            $table->string('kind', 32);
            $table->text('message');
            $table->string('status', 32)->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_contact_messages');
    }
};
