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
            ->assertJsonStructure(['topic', 'subscribe_url', 'deep_link', 'play_store_url']);

        $topic = $res->json('topic');
        $this->assertNotEmpty($topic);
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

        app(WebPushService::class)->notifyFamilyHeadsByUserIds(
            [$user->id],
            'لديك إشعار جديد',
            'طرد بانتظار الاستلام',
            '/family/notifications',
            ['type' => 'distribution_pending']
        );

        Http::assertSent(fn ($request) => ($request->data()['topic'] ?? null) === $topic);
    }
}
