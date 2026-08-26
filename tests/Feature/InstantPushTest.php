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

        $res = $this->getJson('/api/push/instant-channel', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('linked', false);

        $topic = $res->json('topic');
        $intent = (string) $res->json('android_intent');
        $this->assertNotEmpty($topic);
        $this->assertStringStartsWith('ntfy://', (string) $res->json('deep_link'));
        $this->assertStringContainsString('scheme=ntfy', $intent);
        $this->assertStringContainsString('package=io.heckel.ntfy', $intent);
        $this->assertStringContainsString('play.google.com', $intent);
        $this->assertStringNotContainsString('scheme=https', $intent);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'ntfy_topic' => $topic,
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

        $linked = $this->postJson('/api/push/instant-channel/link', [], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('linked', true);

        $oldTopic = $linked->json('topic');
        $this->assertNotEmpty($oldTopic);
        $this->assertNotNull($user->fresh()->ntfy_linked_at);

        $unlinked = $this->postJson('/api/push/instant-channel/unlink', [], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('linked', false);

        $this->assertNotSame($oldTopic, $unlinked->json('topic'));
        $this->assertNull($user->fresh()->ntfy_linked_at);
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
}
