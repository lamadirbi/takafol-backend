<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('push:vapid', function () {
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $this->info('VAPID_PUBLIC_KEY='.$keys['publicKey']);
    $this->info('VAPID_PRIVATE_KEY='.$keys['privateKey']);
    $this->line('ضع القيم في ملف .env ثم أعد تشغيل السيرفر.');
})->purpose('Generate VAPID keys for Web Push');
