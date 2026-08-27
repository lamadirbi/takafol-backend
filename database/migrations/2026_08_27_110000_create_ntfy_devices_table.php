<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ntfy_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_key', 80);
            $table->string('topic', 64);
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_key']);
            $table->unique('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ntfy_devices');
    }
};
