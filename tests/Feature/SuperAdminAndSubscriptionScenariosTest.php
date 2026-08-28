<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class SuperAdminAndSubscriptionScenariosTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_sup01_global_super_can_crud_camps_camp_admin_cannot(): void
    {
        $super = $this->makeGlobalSuper();
        $superToken = $this->loginSuper($super);
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);

        $this->postJson('/api/admin/camps', [
            'name' => 'مخيم منصة',
            'slug' => 'platform-camp',
        ], $this->superHeaders($superToken))->assertCreated();

        $this->getJson('/api/admin/camps', $this->superHeaders($superToken))
            ->assertOk()
            ->assertJsonFragment(['slug' => 'platform-camp']);

        $this->postJson('/api/admin/camps', [
            'name' => 'محظور',
            'slug' => 'forbidden-camp',
        ], $this->campHeaders($camp, $adminToken))->assertForbidden();
    }

    public function test_sup02_registration_requests_review_does_not_create_camp(): void
    {
        $super = $this->makeGlobalSuper();
        $token = $this->loginSuper($super);

        $created = $this->postJson('/api/camp-registration-requests', [
            'applicant_name' => 'خالد',
            'camp_name' => 'مخيم الأمل',
            'whatsapp_phone' => '0591112233',
        ])->assertCreated();

        $list = $this->getJson('/api/admin/camp-registration-requests', $this->superHeaders($token))->assertOk();

        $id = $list->json('data.0.id');
        $this->assertNotNull($id);

        $this->patchJson('/api/admin/camp-registration-requests/'.$id, [
            'status' => 'approved',
            'admin_note' => 'مرحباً بكم',
        ], $this->superHeaders($token))->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseMissing('camps', ['name' => 'مخيم الأمل']);
    }

    public function test_sup02b_super_reviews_platform_contact_messages_camp_admin_cannot(): void
    {
        $super = $this->makeGlobalSuper();
        $token = $this->loginSuper($super);
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);

        $created = $this->postJson('/api/platform-contact-messages', [
            'name' => 'خالد',
            'whatsapp_phone' => '0591112233',
            'kind' => 'inquiry',
            'message' => 'كيف نضيف مسؤولاً ثانياً؟',
        ])->assertCreated();

        $this->getJson('/api/admin/platform-contact-messages', $this->campHeaders($camp, $adminToken))
            ->assertForbidden();

        $list = $this->getJson('/api/admin/platform-contact-messages', $this->superHeaders($token))->assertOk();
        $id = $list->json('data.0.id');
        $this->assertNotNull($id);
        $this->assertSame($created->json('id'), $id);

        $this->patchJson('/api/admin/platform-contact-messages/'.$id, [
            'status' => 'in_progress',
            'admin_note' => 'سيتم الرد اليوم',
        ], $this->superHeaders($token))
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('admin_note', 'سيتم الرد اليوم');

        $this->patchJson('/api/admin/platform-contact-messages/'.$id, [
            'status' => 'closed',
        ], $this->superHeaders($token))
            ->assertOk()
            ->assertJsonPath('status', 'closed');
    }

    public function test_sup03_renewal_approve_extends_subscription(): void
    {
        Storage::fake('public');
        $super = $this->makeGlobalSuper();
        $superToken = $this->loginSuper($super);
        $camp = $this->makeCamp([
            'subscription_valid_until' => now()->toDateString(),
        ]);
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);

        $this->post('/api/admin/camp/subscription-renewal-requests', [
            'image' => $this->fakeJpeg(),
        ], $this->campHeaders($camp, $adminToken))->assertCreated();

        $pending = $this->getJson('/api/admin/subscription-renewal-requests', $this->superHeaders($superToken))->assertOk();

        $renewalId = $pending->json('data.0.id');
        $this->assertNotNull($renewalId);

        $this->patchJson('/api/admin/subscription-renewal-requests/'.$renewalId, [
            'status' => 'approved',
        ], $this->superHeaders($superToken))->assertOk();

        $camp->refresh();
        $this->assertTrue(
            $camp->subscription_valid_until->greaterThan(now()->toDateString())
            || $camp->subscription_valid_until->equalTo(now()->addDays((int) config('subscription.renewal_days'))->toDateString())
            || $camp->subscription_valid_until->gte(now()->startOfDay())
        );
        $this->assertSame(
            now()->addDays((int) config('subscription.renewal_days', 30))->toDateString(),
            $camp->subscription_valid_until->toDateString()
        );
    }

    public function test_sub01_expired_without_grace_blocks_family_login(): void
    {
        Config::set('subscription.grace_days_after_expiry', 0);
        $camp = $this->makeCamp([
            'subscription_valid_until' => now()->subDay()->toDateString(),
        ]);
        $this->makeCampAdmin($camp);
        $pack = $this->makeFamilyWithHead($camp);

        $this->postJson('/api/login', [
            'national_id' => $pack['user']->national_id,
            'serial' => $pack['serial'],
        ], $this->campHeaders($camp))->assertStatus(422);
    }

    public function test_sub02_grace_allows_dashboard_but_blocks_change_requests(): void
    {
        Config::set('subscription.grace_days_after_expiry', 7);
        $camp = $this->makeCamp([
            'subscription_valid_until' => now()->subDay()->toDateString(),
        ]);
        $this->makeCampAdmin($camp);
        $pack = $this->makeFamilyWithHead($camp);

        $token = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $this->getJson('/api/me', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('subscription.in_grace', true);

        $this->getJson('/api/family/dashboard', $this->campHeaders($camp, $token))
            ->assertOk();

        $this->postJson('/api/family/change-requests', [
            'payload' => ['family' => ['phone' => '0593333333']],
        ], $this->campHeaders($camp, $token))
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_payment_required');
    }

    public function test_sub03_hard_block_returns_subscription_expired_on_api(): void
    {
        Config::set('subscription.grace_days_after_expiry', 0);
        $camp = $this->makeCamp([
            'subscription_valid_until' => now()->addDays(10)->toDateString(),
        ]);
        $this->makeCampAdmin($camp);
        $pack = $this->makeFamilyWithHead($camp);
        $token = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $camp->update(['subscription_valid_until' => now()->subDay()->toDateString()]);

        $this->getJson('/api/family/dashboard', $this->campHeaders($camp, $token))
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_expired');

        $this->postJson('/api/logout', [], $this->campHeaders($camp, $token))
            ->assertOk();
    }

    public function test_sub04_duplicate_pending_renewal_is_conflict(): void
    {
        Storage::fake('public');
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->post('/api/admin/camp/subscription-renewal-requests', [
            'image' => $this->fakeJpeg('a.png'),
        ], $this->campHeaders($camp, $token))->assertCreated();

        $this->post('/api/admin/camp/subscription-renewal-requests', [
            'image' => $this->fakeJpeg('b.png'),
        ], $this->campHeaders($camp, $token))->assertStatus(409);
    }

    public function test_ten01_families_are_isolated_between_camps(): void
    {
        $campA = $this->makeCamp(['slug' => 'camp-a']);
        $campB = $this->makeCamp(['slug' => 'camp-b']);
        $adminA = $this->makeCampAdmin($campA);
        $adminB = $this->makeCampAdmin($campB);
        $packA = $this->makeFamilyWithHead($campA, ['head_name' => 'عائلة أ']);
        $this->makeFamilyWithHead($campB, ['head_name' => 'عائلة ب']);

        $tokenA = $this->loginAdmin($adminA, $campA);
        $tokenB = $this->loginAdmin($adminB, $campB);

        $listA = $this->getJson('/api/admin/families', $this->campHeaders($campA, $tokenA))->assertOk();
        $this->assertCount(1, $listA->json('data'));
        $this->assertSame($packA['family']->national_id, $listA->json('data.0.national_id'));

        $listB = $this->getJson('/api/admin/families', $this->campHeaders($campB, $tokenB))->assertOk();
        $this->assertCount(1, $listB->json('data'));
        $this->assertNotSame($packA['family']->national_id, $listB->json('data.0.national_id'));
    }

    public function test_ten02_family_login_with_wrong_camp_slug_fails(): void
    {
        $campA = $this->makeCamp(['slug' => 'alpha']);
        $campB = $this->makeCamp(['slug' => 'beta']);
        $this->makeCampAdmin($campA);
        $this->makeCampAdmin($campB);
        $pack = $this->makeFamilyWithHead($campA);

        $this->postJson('/api/login', [
            'national_id' => $pack['user']->national_id,
            'serial' => $pack['serial'],
        ], $this->campHeaders($campB))->assertStatus(422);
    }

    public function test_sup04_camp_bound_super_cannot_manage_all_camps(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp, ['is_super' => true, 'password' => 'AdminPass123']);
        $token = $this->loginAdmin($admin, $camp);

        $this->getJson('/api/admin/camps', $this->campHeaders($camp, $token))
            ->assertForbidden();
    }
}
