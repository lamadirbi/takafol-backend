<?php

namespace Tests\Feature;

use App\Services\FamilyFormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class FilterReadinessTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_camp_admin_sees_missing_import_columns_for_filters(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $headers = $this->campHeaders($camp, $token);

        app(FamilyFormSchema::class)->applyAdoptedConfig($camp, ['الإسم', 'رقم الهوية']);
        $this->makeFamilyWithHead($camp, [
            'social_status' => null,
            'total_members' => 0,
        ]);

        $res = $this->getJson('/api/admin/filter-readiness', $headers)
            ->assertOk()
            ->assertJsonPath('families', 1);

        $enabled = $res->json('enabled_keys');
        $this->assertContains('head_name', $enabled);
        $this->assertContains('national_id', $enabled);
        $this->assertNotContains('social_status', $enabled);
        $this->assertNotContains('total_members', $enabled);
        $this->assertSame(0, $res->json('filled.social_status'));
        $this->assertSame(0, $res->json('filled.children'));
    }
}
