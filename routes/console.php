<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('push:vapid {--write : Write keys into .env when missing}', function () {
    $envPath = base_path('.env');
    $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    $currentPublic = '';
    $currentPrivate = '';
    if (preg_match('/^VAPID_PUBLIC_KEY=(.*)$/m', $env, $m)) {
        $currentPublic = trim(trim((string) $m[1]), "\"'");
    }
    if (preg_match('/^VAPID_PRIVATE_KEY=(.*)$/m', $env, $m)) {
        $currentPrivate = trim(trim((string) $m[1]), "\"'");
    }

    if ($currentPublic !== '' && $currentPrivate !== '') {
        $this->info($this->option('write') ? 'VAPID keys already present in .env.' : 'VAPID keys already exist. Delete them from .env to regenerate.');

        return;
    }

    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();

    if (! $this->option('write')) {
        $this->info('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->info('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('ضع القيم في ملف .env ثم أعد تشغيل السيرفر. أو شغّل: php artisan push:vapid --write');

        return;
    }

    if (! is_file($envPath)) {
        $this->error('ملف .env غير موجود.');

        return 1;
    }

    if (preg_match('/^VAPID_PUBLIC_KEY=/m', $env)) {
        $updated = preg_replace('/^VAPID_PUBLIC_KEY=.*$/m', 'VAPID_PUBLIC_KEY='.$keys['publicKey'], $env, 1) ?? $env;
        $updated = preg_replace('/^VAPID_PRIVATE_KEY=.*$/m', 'VAPID_PRIVATE_KEY='.$keys['privateKey'], $updated, 1) ?? $updated;
        if (! preg_match('/^VAPID_PRIVATE_KEY=/m', $updated)) {
            $updated .= "\nVAPID_PRIVATE_KEY={$keys['privateKey']}\n";
        }
        file_put_contents($envPath, $updated);
    } else {
        file_put_contents(
            $envPath,
            $env.(str_ends_with($env, "\n") ? '' : "\n").
            "VAPID_PUBLIC_KEY={$keys['publicKey']}\n".
            "VAPID_PRIVATE_KEY={$keys['privateKey']}\n".
            "VAPID_SUBJECT=\"\${APP_URL}\"\n"
        );
    }

    $this->info('تم حفظ مفاتيح Web Push في .env');
})->purpose('Generate VAPID keys for Web Push');
