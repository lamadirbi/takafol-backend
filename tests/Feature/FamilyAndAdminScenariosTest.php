<?php

namespace Tests\Feature;

use App\Models\ChangeRequest;
use App\Models\Distribution;
use App\Models\FamilyMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class FamilyAndAdminScenariosTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_adm01_create_list_update_delete_family(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $nid = $this->nextNationalId('3');

        $created = $this->postJson('/api/admin/families', [
            'national_id' => $nid,
            'head_name' => 'محمد أحمد',
            'total_members' => 2,
            'phone' => '0591111111',
            'social_status' => 'married',
            'financial_status' => 'low',
            'members' => [
                [
                    'name' => 'محمد أحمد',
                    'relationship' => 'رب الأسرة',
                    'gender' => FamilyMember::GENDER_MALE,
                    'date_of_birth' => '1985-01-01',
                ],
                [
                    'name' => 'سارة',
                    'relationship' => 'زوجة',
                    'gender' => FamilyMember::GENDER_FEMALE,
                    'date_of_birth' => '1988-05-01',
                ],
            ],
        ], $this->campHeaders($camp, $token));

        $created->assertCreated();
        $familyId = $created->json('data.id');
        $this->assertNotNull($familyId);

        $this->getJson('/api/admin/families?search=محمد', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonFragment(['national_id' => $nid]);

        $this->patchJson('/api/admin/families/'.$familyId, [
            'phone' => '0592222222',
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('data.phone', '0592222222');

        $this->deleteJson('/api/admin/families/'.$familyId, [], $this->campHeaders($camp, $token))
            ->assertNoContent();

        $this->assertDatabaseMissing('families', ['id' => $familyId]);
    }

    public function test_adm02_rejects_duplicate_national_id(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);

        $this->postJson('/api/admin/families', [
            'national_id' => $pack['family']->national_id,
            'head_name' => 'مكرر',
            'total_members' => 1,
        ], $this->campHeaders($camp, $token))->assertStatus(422);
    }

    public function test_adm03_member_crud(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);
        $familyId = $pack['family']->id;

        $added = $this->postJson('/api/admin/families/'.$familyId.'/members', [
            'name' => 'يوسف',
            'relationship' => 'ابن',
            'gender' => FamilyMember::GENDER_MALE,
            'date_of_birth' => now()->subYears(4)->toDateString(),
        ], $this->campHeaders($camp, $token));

        $added->assertCreated();
        $memberId = $added->json('data.id');

        $this->patchJson('/api/admin/families/'.$familyId.'/members/'.$memberId, [
            'name' => 'يوسف محمد',
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('data.name', 'يوسف محمد');

        $this->deleteJson('/api/admin/families/'.$familyId.'/members/'.$memberId, [], $this->campHeaders($camp, $token))
            ->assertNoContent();
    }

    public function test_adm04_filter_preview_save_and_newborn_on_sqlite(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->makeFamilyWithHead($camp, ['financial_status' => 'low'], [
            [
                'name' => 'رب',
                'relationship' => 'رب الأسرة',
                'gender' => FamilyMember::GENDER_MALE,
                'date_of_birth' => now()->subYears(40)->toDateString(),
            ],
            [
                'name' => 'رضيع',
                'relationship' => 'ابن',
                'gender' => FamilyMember::GENDER_MALE,
                'date_of_birth' => now()->subMonths(3)->toDateString(),
            ],
        ]);

        $this->postJson('/api/admin/camp-filter-records/preview', [
            'filter_scope' => 'family',
            'has_newborn' => true,
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('data.snapshot.families_count', 1);

        $saved = $this->postJson('/api/admin/camp-filter-records', [
            'name' => 'عائلات لديها حديث ولادة',
            'filter_scope' => 'family',
            'has_newborn' => true,
        ], $this->campHeaders($camp, $token));

        $saved->assertCreated();
        $this->assertSame(1, $saved->json('data.snapshot.families_count'));
    }

    public function test_adm05_member_age_filter_on_sqlite(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->makeFamilyWithHead($camp, [], [
            [
                'name' => 'رب',
                'relationship' => 'رب الأسرة',
                'gender' => FamilyMember::GENDER_MALE,
                'date_of_birth' => now()->subYears(40)->toDateString(),
            ],
            [
                'name' => 'طفل',
                'relationship' => 'ابن',
                'gender' => FamilyMember::GENDER_MALE,
                'date_of_birth' => now()->subYears(5)->toDateString(),
            ],
        ]);

        $this->postJson('/api/admin/camp-filter-records/preview', [
            'filter_scope' => 'members',
            'child_age_min' => 3,
            'child_age_max' => 7,
            'member_gender' => 'male',
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('data.snapshot.families_count', 1);
    }

    public function test_adm06_distributions_bulk_confirm_rollback(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);

        $record = $this->postJson('/api/admin/camp-filter-records', [
            'name' => 'كل العائلات',
            'filter_scope' => 'family',
        ], $this->campHeaders($camp, $token));
        $record->assertCreated();
        $recordId = $record->json('data.id');

        $this->postJson('/api/admin/distributions/bulk', [
            'camp_filter_record_id' => $recordId,
            'package_label' => 'طرد غذائي',
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('distributions', [
            'family_id' => $pack['family']->id,
            'package_label' => 'طرد غذائي',
            'status' => Distribution::STATUS_PENDING,
        ]);

        $this->postJson('/api/admin/distributions/confirm-family', [
            'camp_filter_record_id' => $recordId,
            'package_label' => 'طرد غذائي',
            'family_id' => $pack['family']->id,
        ], $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('distributions', [
            'family_id' => $pack['family']->id,
            'status' => Distribution::STATUS_RECEIVED,
        ]);

        $this->postJson('/api/admin/distributions/rollback-family', [
            'camp_filter_record_id' => $recordId,
            'package_label' => 'طرد غذائي',
            'family_id' => $pack['family']->id,
        ], $this->campHeaders($camp, $token))->assertOk();

        $this->assertDatabaseMissing('distributions', [
            'family_id' => $pack['family']->id,
            'package_label' => 'طرد غذائي',
        ]);
    }

    public function test_adm07_announcements_comments_reactions(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);
        $familyToken = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $created = $this->postJson('/api/admin/announcements', [
            'title' => 'خبر توزيع',
            'content' => 'سيتم التوزيع غداً',
        ], $this->campHeaders($camp, $adminToken));
        $created->assertCreated();
        $announcementId = $created->json('data.id');

        $this->postJson('/api/announcements/'.$announcementId.'/comments', [
            'body' => 'شكراً لإدارة المخيم',
        ], $this->campHeaders($camp, $familyToken))->assertCreated();

        $this->postJson('/api/announcements/'.$announcementId.'/reactions/toggle', [
            'type' => 'like',
        ], $this->campHeaders($camp, $familyToken))
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->deleteJson('/api/admin/announcements/'.$announcementId, [], $this->campHeaders($camp, $adminToken))
            ->assertNoContent();
        $this->assertDatabaseMissing('announcements', ['id' => $announcementId]);
    }

    public function test_cr01_family_change_request_approve_and_reject(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);
        $familyToken = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $created = $this->postJson('/api/family/change-requests', [
            'payload' => [
                'family' => ['phone' => '0599999999'],
                'members' => [
                    'add' => [[
                        'name' => 'ليان',
                        'relationship' => 'ابنة',
                        'gender' => FamilyMember::GENDER_FEMALE,
                        'date_of_birth' => now()->subYears(6)->toDateString(),
                    ]],
                ],
            ],
        ], $this->campHeaders($camp, $familyToken));
        $created->assertCreated();
        $requestId = $created->json('data.id');

        $this->getJson('/api/admin/change-requests?status=pending', $this->campHeaders($camp, $adminToken))
            ->assertOk()
            ->assertJsonFragment(['id' => $requestId]);

        $this->postJson('/api/admin/change-requests/'.$requestId.'/approve', [
            'review_note' => 'موافق',
        ], $this->campHeaders($camp, $adminToken))->assertOk();

        $this->assertDatabaseHas('families', [
            'id' => $pack['family']->id,
            'phone' => '0599999999',
        ]);
        $this->assertDatabaseHas('family_members', [
            'family_id' => $pack['family']->id,
            'name' => 'ليان',
        ]);

        $second = $this->postJson('/api/family/change-requests', [
            'payload' => ['family' => ['phone' => '0590000000']],
        ], $this->campHeaders($camp, $familyToken));
        $second->assertCreated();
        $secondId = $second->json('data.id');

        $this->postJson('/api/admin/change-requests/'.$secondId.'/reject', [
            'review_note' => 'بيانات ناقصة',
        ], $this->campHeaders($camp, $adminToken))->assertOk();

        $this->assertSame(ChangeRequest::STATUS_REJECTED, ChangeRequest::query()->find($secondId)?->status);
        $this->assertDatabaseHas('families', [
            'id' => $pack['family']->id,
            'phone' => '0599999999',
        ]);
    }

    public function test_fam01_family_dashboard_shows_packages_not_not_eligible(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $adminToken = $this->loginAdmin($admin, $camp);
        $pack = $this->makeFamilyWithHead($camp);
        $familyToken = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $record = $this->postJson('/api/admin/camp-filter-records', [
            'name' => 'توزيع',
            'filter_scope' => 'family',
        ], $this->campHeaders($camp, $adminToken));
        $recordId = $record->json('data.id');

        $this->postJson('/api/admin/distributions/bulk', [
            'camp_filter_record_id' => $recordId,
            'package_label' => 'طرد صحي',
        ], $this->campHeaders($camp, $adminToken))->assertOk();

        $this->getJson('/api/family/dashboard', $this->campHeaders($camp, $familyToken))
            ->assertOk()
            ->assertJsonPath('family.national_id', $pack['family']->national_id)
            ->assertJsonFragment(['package_label' => 'طرد صحي']);
    }

    public function test_adm08_site_settings_and_package_types(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->putJson('/api/admin/site-settings', [
            'camp_name' => 'مخيم تجريبي',
            'support_phone' => '0590000000',
        ], $this->campHeaders($camp, $token))->assertOk();

        $this->getJson('/api/site-settings', $this->campHeaders($camp))
            ->assertOk()
            ->assertJsonPath('camp_name', 'مخيم تجريبي');

        $this->postJson('/api/admin/package-types', [
            'name' => 'طرد شتوي',
            'description' => 'بطانية وملابس',
        ], $this->campHeaders($camp, $token))->assertCreated();
    }

    public function test_adm09_primary_admin_can_add_secondary_admin(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->postJson('/api/admin/users', [
            'name' => 'مسؤول مساعد',
            'username' => 'helper-admin',
            'password' => 'secret12',
        ], $this->campHeaders($camp, $token))->assertCreated();

        $this->getJson('/api/admin/users', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonFragment(['username' => 'helper-admin']);
    }
}
