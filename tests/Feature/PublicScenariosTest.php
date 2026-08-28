<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class PublicScenariosTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_pub01_lists_only_active_camps(): void
    {
        $active = $this->makeCamp(['name' => 'مخيم ظاهر', 'slug' => 'visible-camp']);
        $this->makeCamp(['name' => 'مخيم مخفي', 'slug' => 'hidden-camp', 'is_active' => false]);

        $this->getJson('/api/camps')
            ->assertOk()
            ->assertJsonFragment(['slug' => $active->slug, 'name' => 'مخيم ظاهر'])
            ->assertJsonMissing(['slug' => 'hidden-camp']);
    }

    public function test_pub02_shows_active_camp_and_hides_inactive(): void
    {
        $camp = $this->makeCamp(['slug' => 'taiba-test']);

        $this->getJson('/api/camps/'.$camp->slug)
            ->assertOk()
            ->assertJsonPath('slug', $camp->slug)
            ->assertJsonStructure(['subscription', 'families_portal_locked', 'landing_page_data']);

        $this->makeCamp(['slug' => 'off-camp', 'is_active' => false]);
        $this->getJson('/api/camps/off-camp')->assertNotFound();
    }

    public function test_pub03_submits_camp_registration_request(): void
    {
        $this->postJson('/api/camp-registration-requests', [
            'applicant_name' => 'أحمد',
            'camp_name' => 'مخيم جديد',
            'whatsapp_phone' => '0591234567',
            'message' => 'نرغب بالانضمام',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'تم استلام طلبك. سيتواصل معك فريق المنصة عبر واتساب قريباً.');

        $this->assertDatabaseHas('camp_registration_requests', [
            'camp_name' => 'مخيم جديد',
            'status' => 'pending',
        ]);
    }

    public function test_pub04_rejects_incomplete_registration_request(): void
    {
        $this->postJson('/api/camp-registration-requests', [
            'applicant_name' => 'أحمد',
        ])->assertStatus(422);
    }

    public function test_pub05_public_announcements_and_site_settings(): void
    {
        $camp = $this->makeCamp();

        $this->getJson('/api/announcements', $this->campHeaders($camp))->assertOk();
        $this->getJson('/api/site-settings', $this->campHeaders($camp))->assertOk();
        $this->getJson('/api/push/public-key')->assertOk()->assertJsonStructure(['public_key']);
    }

    public function test_pub07_submits_platform_contact_message(): void
    {
        $this->postJson('/api/platform-contact-messages', [
            'name' => 'سارة',
            'whatsapp_phone' => '0592533678',
            'camp_name' => 'مخيم طيبة',
            'kind' => 'platform_change',
            'message' => 'نحتاج عموداً جديداً في سجل العائلات.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'تم استلام رسالتكم. الإدارة العليا تراجع الطلبات وتتواصل عبر واتساب عند الحاجة.');

        $this->assertDatabaseHas('platform_contact_messages', [
            'name' => 'سارة',
            'kind' => 'platform_change',
            'status' => 'pending',
        ]);
    }

    public function test_pub08_rejects_incomplete_platform_contact_message(): void
    {
        $this->postJson('/api/platform-contact-messages', [
            'name' => 'سارة',
        ])->assertStatus(422);
    }

    public function test_pub06_unknown_camp_slug_does_not_crash_public_lists(): void
    {
        $this->getJson('/api/announcements', [
            'Accept' => 'application/json',
            'X-Camp-Slug' => 'does-not-exist',
        ])->assertOk();
    }
}
