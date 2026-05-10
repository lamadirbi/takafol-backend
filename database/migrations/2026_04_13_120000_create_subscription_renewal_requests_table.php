<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_renewal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camp_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('status', 32)->default('pending'); // pending|approved|rejected
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['camp_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_renewal_requests');
    }
};

