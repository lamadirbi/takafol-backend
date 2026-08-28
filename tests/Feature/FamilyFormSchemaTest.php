<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Services\FamilyFormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class FamilyFormSchemaTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_camp_admin_can_read_default_family_fields(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $res = $this->getJson('/api/admin/family-form-schema', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonStructure(['fields', 'enabled_fields', 'catalog', 'excel_headers']);

        $this->assertNotEmpty($res->json('excel_headers'));
        $this->assertContains('رقم الهوية', $res->json('excel_headers'));
        $this->assertContains('الإسم', $res->json('excel_headers'));
    }

    public function test_camp_admin_can_hide_fields_and_add_custom_field(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $headers = $this->campHeaders($camp, $token);

        $this->putJson('/api/admin/family-form-schema', [
            'fields' => [
                ['key' => 'national_id', 'enabled' => true, 'required' => true, 'source' => 'catalog'],
                ['key' => 'head_name', 'enabled' => true, 'required' => true, 'source' => 'catalog'],
                ['key' => 'phone', 'enabled' => false, 'required' => false, 'source' => 'catalog'],
                [
                    'key' => 'custom_tent',
                    'enabled' => true,
                    'required' => false,
                    'source' => 'custom',
                    'label' => 'رقم الخيمة',
                    'excel_header' => 'رقم الخيمة',
                    'type' => 'text',
                ],
            ],
        ], $headers)->assertOk()
            ->assertJsonPath('excel_headers.0', 'رقم الهوية')
            ->assertJsonPath('excel_headers.1', 'الإسم');

        $excel = $this->getJson('/api/admin/family-form-schema', $headers)->json('excel_headers');
        $this->assertContains('رقم الخيمة', $excel);
        $this->assertNotContains('رقم الموبايل', $excel);

        $created = $this->postJson('/api/admin/families', [
            'national_id' => $this->nextNationalId('4'),
            'head_name' => 'أحمد علي',
            'extra_data' => ['custom_tent' => '12'],
            'members' => [
                ['name' => 'أحمد علي', 'relationship' => 'رب الأسرة', 'gender' => 'male'],
            ],
        ], $headers)->assertCreated();

        $familyId = $created->json('data.id') ?? $created->json('id');
        $family = Family::query()->find($familyId);
        $this->assertSame('12', $family?->extra_data['custom_tent'] ?? null);
    }

    public function test_schema_maps_excel_row_including_custom_column(): void
    {
        $camp = $this->makeCamp([
            'family_form_config' => [
                'fields' => [
                    ['key' => 'national_id', 'enabled' => true, 'required' => true, 'source' => 'catalog'],
                    ['key' => 'head_name', 'enabled' => true, 'required' => true, 'source' => 'catalog'],
                    [
                        'key' => 'custom_tent',
                        'enabled' => true,
                        'required' => false,
                        'source' => 'custom',
                        'label' => 'رقم الخيمة',
                        'excel_header' => 'رقم الخيمة',
                        'type' => 'text',
                    ],
                ],
            ],
        ]);
        $this->setTenant($camp);

        $mapped = app(FamilyFormSchema::class)->mapImportRow(
            ['الإسم', 'رقم الهوية', 'رقم الخيمة'],
            ['خالد محمد', '401234567', 'B-4'],
            $camp
        );

        $this->assertSame('خالد محمد', $mapped['head_name']);
        $this->assertSame('401234567', $mapped['national_id']);
        $this->assertSame('B-4', $mapped['extra_data']['custom_tent']);
    }
}
