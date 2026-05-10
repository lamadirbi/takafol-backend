<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('site_settings')->insert([
            ['key' => 'camp_name', 'value' => 'مخيم طيبة التربوي', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'support_phone', 'value' => '', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'support_note', 'value' => 'للاستفسار تواصل مع اللجنة عبر القنوات الرسمية.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
