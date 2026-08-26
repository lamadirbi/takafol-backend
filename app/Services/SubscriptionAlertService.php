<?php

namespace App\Services;

use App\Models\Camp;

class SubscriptionAlertService
{
    public function __construct(private readonly WebPushService $webPush) {}

    public function sendDue(): int
    {
        $sent = 0;
        Camp::query()
            ->whereNotNull('subscription_valid_until')
            ->orderBy('id')
            ->each(function (Camp $camp) use (&$sent) {
                $sent += $this->processCamp($camp);
            });

        return $sent;
    }

    private function processCamp(Camp $camp): int
    {
        $expiry = $camp->subscriptionExpiryDay()?->toDateString();
        if ($expiry === null) {
            return 0;
        }

        $alerts = is_array($camp->subscription_alerts_sent) ? $camp->subscription_alerts_sent : [];
        if (($alerts['expiry'] ?? null) !== $expiry) {
            $alerts = ['expiry' => $expiry];
        }

        $meta = $camp->subscriptionAdminMeta();
        $status = (string) ($meta['status'] ?? '');
        $sent = 0;
        $dirty = false;

        if ($status === 'active') {
            $days = (int) ($meta['days_until_expiry'] ?? -1);
            if ($days <= 7 && $days >= 2 && empty($alerts['remind_7d'])) {
                $this->notifyExpiring($camp, $days);
                $alerts['remind_7d'] = true;
                $sent++;
                $dirty = true;
            }
            if ($days <= 1 && $days >= 0 && empty($alerts['remind_1d'])) {
                $this->notifyExpiring($camp, $days);
                $alerts['remind_1d'] = true;
                $sent++;
                $dirty = true;
            }
        }

        if ($status === 'grace' && empty($alerts['grace'])) {
            $this->notifyStatus($camp, 'grace');
            $alerts['grace'] = true;
            $sent++;
            $dirty = true;
        }

        if ($status === 'locked' && empty($alerts['locked'])) {
            $this->notifyStatus($camp, 'locked');
            $alerts['locked'] = true;
            $sent++;
            $dirty = true;
        }

        if ($dirty) {
            $camp->subscription_alerts_sent = $alerts;
            $camp->save();
        }

        return $sent;
    }

    private function notifyExpiring(Camp $camp, int $days): void
    {
        $when = $days <= 0 ? 'اليوم' : ($days === 1 ? 'غداً' : 'خلال '.$days.' أيام');
        $title = 'اشتراك المخيم ينتهي قريباً';
        $body = $camp->name.' — الاشتراك ينتهي '.$when.'.';
        $data = [
            'type' => 'subscription_expiring',
            'camp_id' => $camp->id,
            'days_until_expiry' => $days,
        ];

        $this->webPush->notifyCampAdmins((int) $camp->id, $title, $body, '/admin/dashboard', $data);
        $this->webPush->notifyGlobalSuperAdmins($title, $body, '/super-admin/camps/'.$camp->id, $data);
    }

    private function notifyStatus(Camp $camp, string $status): void
    {
        $title = $status === 'grace' ? 'المخيم دخل فترة السماح' : 'توقف اشتراك مخيم';
        $body = $status === 'grace'
            ? $camp->name.' انتهى اشتراكه ودخل فترة السماح.'
            : $camp->name.' توقف بسبب انتهاء الاشتراك.';
        $data = [
            'type' => $status === 'grace' ? 'subscription_grace' : 'subscription_locked',
            'camp_id' => $camp->id,
        ];

        $this->webPush->notifyGlobalSuperAdmins($title, $body, '/super-admin/camps/'.$camp->id, $data);
    }
}
