<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camp_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_name', 160);
            $table->string('camp_name', 255);
            $table->string('whatsapp_phone', 32);
            $table->text('message')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camp_registration_requests');
    }
};
