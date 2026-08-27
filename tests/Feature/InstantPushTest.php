<?php

namespace Tests\Feature;

use App\Services\InstantPushService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class InstantPushTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_public_instant_app_links(): void
    {
        $this->getJson('/api/push/instant-app')
            ->assertOk()
            ->assertJsonPath('app', 'ntfy')
            ->assertJsonStructure(['play_store_url', 'app_store_url']);
    }

    public function test_family_gets_private_ntfy_channel(): void
    {
        $camp = $this->makeCamp();
        ['user' => $user, 'serial' => $serial] = $this->makeFamilyWithHead($camp);
        $token = $this->loginFamily($user, $serial, $camp);

        $res = $this->getJson('/api/push/instant-channel?device_key=phone-one', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('linked', false)
            ->assertJsonPath('installed', false);

        $topic = $res->json('topic');
        $intent = (string) $res->json('android_intent');
        $this->assertNotEmpty($topic);
        $this->assertStringStartsWith('ntfy://', (string) $res->json('deep_link'));
        $this->assertStringContainsString('scheme=ntfy', $intent);
        $this->assertStringContainsString('package=io.heckel.ntfy', $intent);
        $this->assertStringContainsString('play.google.com', $intent);
        $this->assertStringNotContainsString('scheme=https', $intent);
        $this->assertDatabaseHas('ntfy_devices', [
            'user_id' => $user->id,
            'device_key' => 'phone-one',
            'topic' => $topic,
        ]);
    }

    public function test_notify_user_posts_to_ntfy(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $camp = $this->makeCamp();
        ['user' => $user] = $this->makeFamilyWithHead($camp);
        $topic = app(InstantPushService::class)->ensureTopic($user);
        app(InstantPushService::class)->markLinked($user);

        app(WebPushService::class)->notifyUser(
            $user,
            'طرد بانتظارك',
            'يوجد طرد جديد',
            '/family/notifications',
            ['type' => 'distribution_pending']
        );

        Http::assertSent(function ($request) use ($topic, $camp) {
            $data = $request->data();
            $click = $data['click'] ?? '';

            return $request->url() === 'https://ntfy.sh'
                && ($data['topic'] ?? null) === $topic
                && ($data['title'] ?? null) === 'طرد بانتظارك'
                && str_contains($click, '/'.$camp->slug.'/family/notifications')
                && isset($data['actions'][0]['url']);
        });
    }

    public function test_family_package_notify_hits_ntfy_without_waiting_for_queue(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);
        \Illuminate\Support\Facades\Queue::fake();

        $camp = $this->makeCamp();
        $this->setTenant($camp);
        ['user' => $user] = $this->makeFamilyWithHead($camp);
        $topic = app(InstantPushService::class)->ensureTopic($user);
        app(InstantPushService::class)->markLinked($user);

        app(WebPushService::class)->notifyFamilyHeadsByUserIds(
            [$user->id],
            'لديك إشعار جديد',
            'طرد بانتظار الاستلام',
            '/family/notifications',
            ['type' => 'distribution_pending']
        );

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $topic);
    }

    public function test_unlinked_user_does_not_receive_ntfy(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $camp = $this->makeCamp();
        ['user' => $user] = $this->makeFamilyWithHead($camp);
        app(InstantPushService::class)->ensureTopic($user);

        app(WebPushService::class)->notifyUser(
            $user,
            'طرد بانتظارك',
            'يوجد طرد جديد',
            '/family/notifications',
            ['type' => 'distribution_pending']
        );

        Http::assertNothingSent();
    }

    public function test_family_can_link_and_unlink_ntfy_app(): void
    {
        $camp = $this->makeCamp();
        ['user' => $user, 'serial' => $serial] = $this->makeFamilyWithHead($camp);
        $token = $this->loginFamily($user, $serial, $camp);
        $headers = $this->campHeaders($camp, $token);
        $body = ['device_key' => 'phone-one'];

        $this->postJson('/api/push/instant-channel/link', $body, $headers)
            ->assertStatus(409);

        $this->postJson('/api/push/instant-channel/installed', $body, $headers)
            ->assertOk()
            ->assertJsonPath('installed', true)
            ->assertJsonPath('linked', false);

        $linked = $this->postJson('/api/push/instant-channel/link', $body, $headers)
            ->assertOk()
            ->assertJsonPath('linked', true);

        $oldTopic = $linked->json('topic');
        $this->assertNotEmpty($oldTopic);
        $this->assertDatabaseHas('ntfy_devices', [
            'user_id' => $user->id,
            'device_key' => 'phone-one',
            'topic' => $oldTopic,
        ]);

        $unlinked = $this->postJson('/api/push/instant-channel/unlink', $body, $headers)
            ->assertOk()
            ->assertJsonPath('linked', false);

        $this->assertNotSame($oldTopic, $unlinked->json('topic'));
        $this->assertDatabaseMissing('ntfy_devices', [
            'user_id' => $user->id,
            'device_key' => 'phone-one',
            'topic' => $oldTopic,
        ]);
    }

    public function test_same_account_links_each_device_separately(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $camp = $this->makeCamp();
        ['user' => $user] = $this->makeFamilyWithHead($camp);
        $push = app(InstantPushService::class);
        $first = $push->markLinked($user, 'phone-aaa');
        $second = $push->markLinked($user, 'phone-bbb');
        $this->assertNotSame($first['topic'], $second['topic']);

        app(WebPushService::class)->notifyUser(
            $user,
            'طرد بانتظارك',
            'يوجد طرد جديد',
            '/family/notifications',
            ['type' => 'distribution_pending']
        );

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $first['topic']);
        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $second['topic']);

        $push->unlink($user, 'phone-aaa');
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        app(WebPushService::class)->notifyUser(
            $user,
            'طرد بانتظارك',
            'يوجد طرد جديد',
            '/family/notifications',
            ['type' => 'distribution_pending']
        );

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $second['topic']);
        Http::assertNotSent(fn ($request) => ($request->data()['topic'] ?? null) === $first['topic']);
    }

    public function test_public_key_reports_enabled_flag(): void
    {
        $this->getJson('/api/push/public-key')
            ->assertOk()
            ->assertJsonStructure(['public_key', 'enabled']);
    }

    public function test_family_can_subscribe_web_push(): void
    {
        $camp = $this->makeCamp();
        ['user' => $user, 'serial' => $serial] = $this->makeFamilyWithHead($camp);
        $token = $this->loginFamily($user, $serial, $camp);

        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/family-test',
            'keys' => [
                'p256dh' => 'family-public-key',
                'auth' => 'family-auth-token',
            ],
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'public_key' => 'family-public-key',
        ]);
    }

    public function test_camp_admin_can_subscribe_web_push(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/admin-test',
            'keys' => [
                'p256dh' => 'admin-public-key',
                'auth' => 'admin-auth-token',
            ],
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $admin->id,
            'public_key' => 'admin-public-key',
        ]);
    }

    public function test_super_admin_can_link_ntfy_without_camp(): void
    {
        $super = $this->makeGlobalSuper();
        $token = $this->loginSuper($super);

        $this->postJson('/api/push/instant-channel/installed', ['device_key' => 'phone-one'], $this->superHeaders($token))
            ->assertOk();
        $this->postJson('/api/push/instant-channel/link', ['device_key' => 'phone-one'], $this->superHeaders($token))
            ->assertOk()
            ->assertJsonPath('linked', true);

        $this->assertDatabaseHas('ntfy_devices', [
            'user_id' => $super->id,
            'device_key' => 'phone-one',
        ]);
    }

    public function test_camp_registration_notifies_linked_super_admin(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $super = $this->makeGlobalSuper();
        $topic = app(InstantPushService::class)->ensureTopic($super);
        app(InstantPushService::class)->markLinked($super);

        $this->postJson('/api/camp-registration-requests', [
            'applicant_name' => 'خالد',
            'camp_name' => 'مخيم النور',
            'whatsapp_phone' => '0591112233',
        ])->assertCreated();

        Http::assertSent(function ($request) use ($topic) {
            $data = $request->data();
            $click = (string) ($data['click'] ?? '');

            return ($data['topic'] ?? null) === $topic
                && ($data['title'] ?? null) === 'طلب تسجيل مخيم جديد'
                && str_contains($click, '/super-admin/requests');
        });
    }

    public function test_renewal_request_notifies_linked_super_admin(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');

        $super = $this->makeGlobalSuper();
        $topic = app(InstantPushService::class)->ensureTopic($super);
        app(InstantPushService::class)->markLinked($super);

        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->post('/api/admin/camp/subscription-renewal-requests', [
            'image' => $this->fakeJpeg(),
        ], $this->campHeaders($camp, $token))->assertCreated();

        Http::assertSent(function ($request) use ($topic) {
            $data = $request->data();
            $click = (string) ($data['click'] ?? '');

            return ($data['topic'] ?? null) === $topic
                && ($data['title'] ?? null) === 'طلب تجديد اشتراك'
                && str_contains($click, '/super-admin/renewals');
        });
    }

    public function test_family_change_request_notifies_camp_admin_only(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $camp = $this->makeCamp();
        $other = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $otherAdmin = $this->makeCampAdmin($other);
        $adminTopic = app(InstantPushService::class)->ensureTopic($admin);
        $otherTopic = app(InstantPushService::class)->ensureTopic($otherAdmin);
        app(InstantPushService::class)->markLinked($admin);
        app(InstantPushService::class)->markLinked($otherAdmin);

        $pack = $this->makeFamilyWithHead($camp);
        $familyToken = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $this->postJson('/api/family/change-requests', [
            'payload' => [
                'family' => ['phone' => '0591234567'],
            ],
        ], $this->campHeaders($camp, $familyToken))->assertCreated();

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $adminTopic
            && ($request->data()['title'] ?? null) === 'طلب تعديل بيانات جديد');
        Http::assertNotSent(fn ($request) => ($request->data()['topic'] ?? null) === $otherTopic);
    }

    public function test_renewal_decision_notifies_camp_admin(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');

        $super = $this->makeGlobalSuper();
        $superToken = $this->loginSuper($super);
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);
        $topic = app(InstantPushService::class)->ensureTopic($admin);
        app(InstantPushService::class)->markLinked($admin);

        $this->post('/api/admin/camp/subscription-renewal-requests', [
            'image' => $this->fakeJpeg(),
        ], $this->campHeaders($camp, $adminToken))->assertCreated();

        $pending = $this->getJson('/api/admin/subscription-renewal-requests', $this->superHeaders($superToken))->assertOk();
        $renewalId = $pending->json('data.0.id');

        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $this->patchJson('/api/admin/subscription-renewal-requests/'.$renewalId, [
            'status' => 'approved',
        ], $this->superHeaders($superToken))->assertOk();

        Http::assertSent(function ($request) use ($topic, $camp) {
            $data = $request->data();
            $click = (string) ($data['click'] ?? '');

            return ($data['topic'] ?? null) === $topic
                && ($data['title'] ?? null) === 'تم قبول تجديد الاشتراك'
                && str_contains($click, '/'.$camp->slug.'/admin/dashboard');
        });
    }

    public function test_change_request_decision_notifies_family(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);
        $familyTopic = app(InstantPushService::class)->ensureTopic($pack['user']);
        app(InstantPushService::class)->markLinked($pack['user']);
        $familyToken = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $created = $this->postJson('/api/family/change-requests', [
            'payload' => ['family' => ['phone' => '0591234567']],
        ], $this->campHeaders($camp, $familyToken))->assertCreated();

        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $this->postJson('/api/admin/change-requests/'.$created->json('data.id').'/approve', [
            'review_note' => 'موافق',
        ], $this->campHeaders($camp, $adminToken))->assertOk();

        Http::assertSent(function ($request) use ($familyTopic, $camp) {
            $data = $request->data();
            $click = (string) ($data['click'] ?? '');

            return ($data['topic'] ?? null) === $familyTopic
                && ($data['title'] ?? null) === 'تم قبول طلب التعديل'
                && str_contains($click, '/'.$camp->slug.'/family/change-requests');
        });
    }

    public function test_pending_package_cancel_notifies_family(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);
        $familyTopic = app(InstantPushService::class)->ensureTopic($pack['user']);
        app(InstantPushService::class)->markLinked($pack['user']);

        $record = $this->postJson('/api/admin/camp-filter-records', [
            'name' => 'كل العائلات',
            'filter_scope' => 'family',
        ], $this->campHeaders($camp, $token))->assertCreated();

        $this->postJson('/api/admin/distributions/bulk', [
            'camp_filter_record_id' => $record->json('data.id'),
            'package_label' => 'طرد غذائي',
        ], $this->campHeaders($camp, $token))->assertOk();

        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $this->postJson('/api/admin/distributions/bulk-cancel', [
            'camp_filter_record_id' => $record->json('data.id'),
            'package_label' => 'طرد غذائي',
        ], $this->campHeaders($camp, $token))->assertOk();

        Http::assertSent(function ($request) use ($familyTopic) {
            $data = $request->data();

            return ($data['topic'] ?? null) === $familyTopic
                && ($data['title'] ?? null) === 'تم إلغاء طرد';
        });
    }

    public function test_subscription_expiry_reminder_notifies_camp_and_super_once(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);

        $super = $this->makeGlobalSuper();
        $superTopic = app(InstantPushService::class)->ensureTopic($super);
        app(InstantPushService::class)->markLinked($super);

        $camp = $this->makeCamp([
            'subscription_valid_until' => now()->addDays(7)->toDateString(),
        ]);
        $admin = $this->makeCampAdmin($camp);
        $adminTopic = app(InstantPushService::class)->ensureTopic($admin);
        app(InstantPushService::class)->markLinked($admin);

        $sent = app(\App\Services\SubscriptionAlertService::class)->sendDue();
        $this->assertGreaterThanOrEqual(1, $sent);

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $adminTopic
            && ($request->data()['title'] ?? null) === 'اشتراك المخيم ينتهي قريباً');
        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $superTopic
            && str_contains((string) ($request->data()['click'] ?? ''), '/super-admin/camps/'.$camp->id));

        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);
        app(\App\Services\SubscriptionAlertService::class)->sendDue();
        Http::assertNothingSent();
    }

    public function test_locked_camp_notifies_super_admin(): void
    {
        Http::fake([
            'https://ntfy.sh' => Http::response(['id' => 'ok'], 200),
            'https://ntfy.sh/*' => Http::response(['id' => 'ok'], 200),
        ]);
        \Illuminate\Support\Facades\Config::set('subscription.grace_days_after_expiry', 0);

        $super = $this->makeGlobalSuper();
        $superTopic = app(InstantPushService::class)->ensureTopic($super);
        app(InstantPushService::class)->markLinked($super);

        $camp = $this->makeCamp([
            'subscription_valid_until' => now()->subDay()->toDateString(),
        ]);
        $admin = $this->makeCampAdmin($camp);
        $adminTopic = app(InstantPushService::class)->ensureTopic($admin);
        app(InstantPushService::class)->markLinked($admin);

        app(\App\Services\SubscriptionAlertService::class)->sendDue();

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $superTopic
            && ($request->data()['title'] ?? null) === 'توقف اشتراك مخيم');
        Http::assertNotSent(fn ($request) => ($request->data()['topic'] ?? null) === $adminTopic);
    }
}
