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

    public function test_excel_headers_become_camp_fields_and_reuse_custom_keys(): void
    {
        $camp = $this->makeCamp();
        $schema = app(FamilyFormSchema::class);

        $first = $schema->adoptFromExcelHeaders([
            'الاسم',
            'رقم الهوية',
            'رقم الخيمة',
            'ملاحظات',
        ], $camp);

        $keys = collect($first['fields'])->where('enabled', true)->pluck('key')->all();
        $this->assertContains('head_name', $keys);
        $this->assertContains('national_id', $keys);
        $this->assertNotContains('phone', collect($first['fields'])->where('enabled', true)->pluck('key')->all());

        $custom = collect($first['fields'])->where('source', 'custom')->values();
        $this->assertCount(2, $custom);
        $this->assertSame('رقم الخيمة', $custom[0]['excel_header']);
        $this->assertSame('ملاحظات', $custom[1]['excel_header']);
        $tentKey = $custom[0]['key'];

        $camp->family_form_config = $first;
        $camp->save();

        $second = $schema->adoptFromExcelHeaders([
            'الإسم',
            'رقم الهوية',
            'رقم الخيمة',
        ], $camp->fresh());

        $reused = collect($second['fields'])->firstWhere('excel_header', 'رقم الخيمة');
        $this->assertSame($tentKey, $reused['key'] ?? null);
        $this->assertNull(collect($second['fields'])->firstWhere('excel_header', 'ملاحظات'));
    }

    public function test_importing_existing_excel_adopts_columns_and_stores_extra_data(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('families_', true).'.xlsx';
        $writer = \OpenSpout\Writer\Common\Creator\WriterFactory::createFromFile($path);
        $writer->openToFile($path);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['الإسم', 'رقم الهوية', 'رقم الخيمة']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['خالد محمد', '401234567', 'B-4']));
        $writer->close();

        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'families.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $res = $this->post('/api/admin/import/families-excel', [
            'file' => $file,
        ], $this->campHeaders($camp, $token))->assertOk();

        $this->assertSame(1, $res->json('created'));
        $this->assertContains('رقم الخيمة', $res->json('adopted_headers'));

        $camp->refresh();
        $custom = collect($camp->family_form_config['fields'] ?? [])->firstWhere('excel_header', 'رقم الخيمة');
        $this->assertNotEmpty($custom['key'] ?? null);

        $family = Family::query()->where('national_id', '401234567')->first();
        $this->assertSame('B-4', $family?->extra_data[$custom['key']] ?? null);

        @unlink($path);
    }

    public function test_import_skips_title_row_and_reads_camp_sheet_headers(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $schema = app(FamilyFormSchema::class);

        $this->assertFalse($schema->rowLooksLikeHeader(['اسم المخيم:', '', 'طيبة', 'العنوان:', 'شارع القبة']));
        $this->assertTrue($schema->rowLooksLikeHeader([
            '#',
            'الاسم رباعي ',
            'رقم الهوية',
            'اسم الزوجة رباعي ',
            'رقم الهوية ',
            'رقم الجوال',
            'عدد افراد الاسرة ',
        ]));

        $headers = $schema->canonicalizeExcelHeaders([
            '#',
            'الاسم رباعي ',
            'رقم الهوية',
            'اسم الزوجة رباعي ',
            'رقم الهوية ',
            'رقم الجوال',
            'عدد افراد الاسرة ',
        ]);
        $this->assertSame('رقم هوية الزوجة', $headers[4]);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('camp_sheet_', true).'.xlsx';
        $writer = \OpenSpout\Writer\Common\Creator\WriterFactory::createFromFile($path);
        $writer->openToFile($path);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['اسم المخيم:', '', 'طيبة', '', '', '', 'العنوان:', '', 'شارع القبة']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([
            '#',
            'الاسم رباعي ',
            'رقم الهوية',
            'اسم الزوجة رباعي ',
            'رقم الهوية ',
            'رقم الجوال',
            'عدد افراد الاسرة ',
            'تاريخ الاستلام ',
            'التوقيع',
        ]));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([
            1,
            'ابتسام احمد محمد',
            913821955,
            'جمال علي حسن',
            923257430,
            599615025,
            2,
            '',
            '',
        ]));
        $writer->close();

        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'camp.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $res = $this->post('/api/admin/import/families-excel', [
            'file' => $file,
        ], $this->campHeaders($camp, $token))->assertOk();

        $this->assertSame(1, $res->json('created'));
        $this->assertSame(0, $res->json('skipped'));

        $family = Family::query()->where('national_id', '913821955')->first();
        $this->assertSame('ابتسام احمد محمد', $family?->head_name);
        $this->assertSame('جمال علي حسن', $family?->spouse_name);
        $this->assertSame('923257430', (string) $family?->spouse_national_id);
        $this->assertSame(2, $family?->total_members);

        @unlink($path);
    }

    public function test_excel_template_includes_filter_columns_and_has_no_family_data(): void
    {
        $camp = $this->makeCamp(['name' => 'مخيم تجريبي']);
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $res = $this->get('/api/admin/import/families-excel-template', $this->campHeaders($camp, $token))
            ->assertOk();

        $content = method_exists($res, 'streamedContent') ? $res->streamedContent() : $res->getContent();
        $this->assertStringStartsWith('PK', $content);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('template_', true).'.xlsx';
        file_put_contents($path, $content);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $shared = (string) $zip->getFromName('xl/sharedStrings.xml');
        $xml = $sheet.$shared;
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('اسم المخيم', $xml);
        $this->assertStringContainsString('مخيم تجريبي', $xml);
        $this->assertStringContainsString('رقم الهوية', $xml);
        $this->assertStringContainsString('الحالة الاجتماعية', $xml);
        $this->assertStringContainsString('عدد افراد الاسرة الكلي', $xml);
        $this->assertStringContainsString('تاريخ الميلاد', $xml);
        $this->assertStringContainsString('صلة القرابة 1', $xml);
        $this->assertStringContainsString('اسم الفرد 1', $xml);
        $this->assertStringContainsString('جنس الفرد 1', $xml);
        $this->assertStringNotContainsString('ابتسام', $xml);
        $this->assertStringNotContainsString('913821955', $xml);
    }

    public function test_import_saves_extra_members_from_filter_template_columns(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $headers = FamilyFormSchema::filterTemplateHeaders();
        $row = array_fill(0, count($headers), '');
        $index = array_flip($headers);
        $row[$index['الإسم']] = 'أحمد علي';
        $row[$index['رقم الهوية']] = '401234567';
        $row[$index['الجنس']] = 'ذكر';
        $row[$index['تاريخ الميلاد']] = '1980-01-01';
        $row[$index['الحالة الاجتماعية']] = 'متزوج';
        $row[$index['اسم الزوجة رباعي']] = 'سارة محمود';
        $row[$index['رقم هوية الزوجة']] = '401234568';
        $row[$index['عدد افراد الاسرة الكلي']] = 4;
        $row[$index['اسم الفرد 1']] = 'يوسف أحمد';
        $row[$index['صلة القرابة 1']] = 'ابن';
        $row[$index['جنس الفرد 1']] = 'ذكر';
        $row[$index['تاريخ ميلاد الفرد 1']] = '2026-01-15';
        $row[$index['اسم الفرد 2']] = 'ليان أحمد';
        $row[$index['صلة القرابة 2']] = 'ابنة';
        $row[$index['جنس الفرد 2']] = 'أنثى';
        $row[$index['تاريخ ميلاد الفرد 2']] = '2018-06-01';

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('filter_tpl_', true).'.xlsx';
        $writer = \OpenSpout\Writer\Common\Creator\WriterFactory::createFromFile($path);
        $writer->openToFile($path);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['اسم المخيم:', 'مخيم تجريبي']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
        $writer->close();

        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'families.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $this->post('/api/admin/import/families-excel', [
            'file' => $file,
        ], $this->campHeaders($camp, $token))->assertOk()->assertJsonPath('created', 1);

        $family = Family::query()->where('national_id', '401234567')->first();
        $this->assertNotNull($family);
        $this->assertSame('married', $family->social_status);
        $this->assertSame(4, $family->total_members);
        $this->assertTrue($family->members()->where('relationship', 'ابن')->where('name', 'يوسف أحمد')->exists());
        $this->assertTrue($family->members()->where('relationship', 'ابنة')->where('name', 'ليان أحمد')->exists());
        $child = $family->members()->where('name', 'يوسف أحمد')->first();
        $this->assertSame('2026-01-15', optional($child?->date_of_birth)->toDateString());

        @unlink($path);
    }
}
