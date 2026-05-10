<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AnnouncementReaction;
use App\Models\CampFilterRecord;
use App\Models\Comment;
use App\Models\Distribution;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\PackageType;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    private function dobFromYears(int $years): string
    {
        // تاريخ تقريبي: السنة - السنوات + شهر/يوم عشوائي
        return Carbon::now()
            ->subYears(max(0, $years))
            ->subMonths(random_int(0, 11))
            ->subDays(random_int(0, 27))
            ->toDateString();
    }

    private function dobNewborn(): string
    {
        // حديث الولادة: أقل من 12 شهر
        return Carbon::now()
            ->subMonths(random_int(0, 11))
            ->subDays(random_int(0, 27))
            ->toDateString();
    }

    /**
     * بيانات وهمية كاملة للاختبار (تشغيل: php artisan migrate:fresh --seed).
     */
    public function run(): void
    {
        // 1. Create Camps
        $taiba = \App\Models\Camp::query()->updateOrCreate(
            ['slug' => 'taiba'],
            [
                'name' => 'مخيم طيبة التربوي',
                'logo_path' => null,
                'is_active' => true,
                'landing_page_data' => [
                    'hero_title' => 'نعمل معاً لتنظيم المساعدات… كرامة، شفافية، وأمل (طيبة)',
                    'hero_description' => 'منصة تَكافل تساعد اللجنة والعائلات على متابعة الطرود والتواصل بسهولة.',
                ],
            ]
        );

        $gaza = \App\Models\Camp::query()->updateOrCreate(
            ['slug' => 'gaza'],
            [
                'name' => 'مخيم غزة الصمود',
                'logo_path' => null,
                'is_active' => true,
                'landing_page_data' => [
                    'hero_title' => 'معاً لأجل غزة… شفافية وأمل (غزة)',
                    'hero_description' => 'منصة لتنظيم توزيع المساعدات في مخيم غزة الصمود.',
                ],
            ]
        );

        // 2. Set Taiba as the current camp for seeding existing data
        \Illuminate\Support\Facades\App::instance('current_camp_id', $taiba->id);

        // Seeder مبسّط: فقط حساب الأدمن (بدون أي عائلات/أفراد/طرود/أخبار).
        $admin = User::withoutGlobalScopes()->updateOrCreate(
            ['national_id' => '1000000000'],
            [
                'name' => 'لجنة المخيم — مسؤول رئيسي',
                'username' => '100100',
                'email' => null,
                'password' => '200200',
                'role' => User::ROLE_ADMIN,
                'is_super' => true,
                'camp_id' => $taiba->id,
            ]
        );

        // تأكد أن username مضبوط دائماً للأدمن حتى عند وجود سجل سابق
        if ($admin->username !== '100100') {
            $admin->username = '100100';
            $admin->save();
        }

        $taiba->update(['primary_admin_user_id' => $admin->id]);

        // 3. Create a Super Admin (Global - no camp)
        // مهم: عند ضبط current_camp_id يتم تطبيق tenant scope على User (camp_id).
        // السوبر أدمن العالمي camp_id=null، لذلك يجب إنشاؤه/تحديثه بدون Global Scope حتى لا يحاول إنشاؤه مرتين.
        // ملاحظة: BelongsToTenant يفرض camp_id عند الإنشاء عبر Eloquent.
        // نستخدم Query Builder لتجاوز هذا السلوك وإبقاء camp_id=null دائماً.
        DB::table('users')->updateOrInsert(
            ['national_id' => '0000000000'],
            [
                'name' => 'Super Administrator',
                'username' => 'superadmin',
                'email' => 'super@taiba.local',
                'password' => Hash::make('SuperPassword123!'),
                'role' => User::ROLE_ADMIN,
                'is_super' => true,
                'camp_id' => null, // Super admin global (not bound to camp)
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->command?->info('=== بيانات Seeder (مبسطة) ===');
        $this->command?->info('دخول الإدارة (طيبة): username=100100 | password=200200');
        $this->command?->info('دخول Super Admin: username=superadmin | password=SuperPassword123!');
        $this->command?->info('============================');

        // Note: The rest of the seeding logic (families, announcements etc)
        // will automatically use $taiba->id due to the App instance and Trait.

        return;

        $demoFamilies = [
            [
                'national_id' => '2000000000',
                'head_name' => 'محمد أحمد',
                'phone' => '0501111111',
                'social_status' => 'married',
                'financial_status' => 'low',
                'file_status' => 'جاري',
                'members' => [
                    ['name' => 'محمد أحمد', 'date_of_birth' => $this->dobFromYears(38), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'سارة', 'date_of_birth' => $this->dobFromYears(32), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'ليان', 'date_of_birth' => $this->dobFromYears(8), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'يوسف', 'date_of_birth' => $this->dobFromYears(3), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'حديث الولادة', 'date_of_birth' => $this->dobNewborn(), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
            [
                'national_id' => '2100000001',
                'head_name' => 'خالد العتيبي',
                'phone' => '0502222222',
                'social_status' => 'married',
                'financial_status' => 'medium',
                'file_status' => 'مكتمل',
                'members' => [
                    ['name' => 'خالد العتيبي', 'date_of_birth' => $this->dobFromYears(45), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'نورة', 'date_of_birth' => $this->dobFromYears(40), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'سالم', 'date_of_birth' => $this->dobFromYears(4), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
            [
                'national_id' => '2100000002',
                'head_name' => 'فاطمة الزهراني',
                'phone' => '0503333333',
                'social_status' => 'widowed',
                'financial_status' => 'low',
                'file_status' => 'جاري',
                'members' => [
                    ['name' => 'فاطمة الزهراني', 'date_of_birth' => $this->dobFromYears(52), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'رنا', 'date_of_birth' => $this->dobFromYears(19), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'هدى', 'date_of_birth' => $this->dobFromYears(2), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000003',
                'head_name' => 'عبدالله الشهري',
                'phone' => '0504444444',
                'social_status' => 'separated',
                'financial_status' => 'low',
                'file_status' => 'معلق',
                'members' => [
                    ['name' => 'عبدالله الشهري', 'date_of_birth' => $this->dobFromYears(29), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
            [
                'national_id' => '2100000004',
                'head_name' => 'ناصر القحطاني',
                'phone' => '0505555555',
                'social_status' => 'married',
                'financial_status' => 'medium',
                'file_status' => 'جاري',
                'members' => [
                    ['name' => 'ناصر القحطاني', 'date_of_birth' => $this->dobFromYears(41), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'هند', 'date_of_birth' => $this->dobFromYears(36), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'عمر', 'date_of_birth' => $this->dobFromYears(12), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'دانة', 'date_of_birth' => $this->dobFromYears(9), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'لولوة', 'date_of_birth' => $this->dobFromYears(6), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000005',
                'head_name' => 'منى الغامدي',
                'phone' => '0506666666',
                'social_status' => 'divorced',
                'financial_status' => 'low',
                'file_status' => 'مكتمل',
                'members' => [
                    ['name' => 'منى الغامدي', 'date_of_birth' => $this->dobFromYears(44), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'سعد', 'date_of_birth' => $this->dobFromYears(16), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
        ];

        // توليد عائلات إضافية حتى نصل 30 عائلة
        $social = ['married', 'widowed', 'separated', 'divorced', 'abandoned'];
        $financial = ['low', 'medium', 'good'];
        $fileStatuses = ['جاري', 'مكتمل', 'معلق'];
        $firstNamesMale = ['أحمد', 'محمد', 'عبدالله', 'خالد', 'ناصر', 'سعيد', 'فيصل', 'محمود', 'وليد', 'عمر', 'يوسف', 'سلمان'];
        $firstNamesFemale = ['نورة', 'سارة', 'هند', 'منى', 'فاطمة', 'ريم', 'دانة', 'ليان', 'لولوة', 'هدى', 'رنا', 'أمل'];
        $lastNames = ['الزهراني', 'القحطاني', 'العتيبي', 'الغامدي', 'الشهري', 'الشمري', 'الحربي', 'التميمي', 'العنزي', 'السبيعي'];
        $demoAreas = ['حي النور', 'حي السلام', 'حي الربيع', 'حي الروضة', 'حي اليرموك', 'حي الأمل'];
        $baseNid = 2200000000;
        $existingNids = collect($demoFamilies)->pluck('national_id')->all();

        for ($i = 0; count($demoFamilies) < $TARGET_FAMILIES; $i++) {
            $nid = (string) ($baseNid + $i);
            if (in_array($nid, $existingNids, true)) {
                continue;
            }

            $isMaleHead = $i % 2 === 0;
            $fn = $isMaleHead ? $firstNamesMale[$i % count($firstNamesMale)] : $firstNamesFemale[$i % count($firstNamesFemale)];
            $ln = $lastNames[$i % count($lastNames)];
            $head = $fn.' '.$ln;
            $phone = '05'.str_pad((string) (70000000 + $i), 8, '0', STR_PAD_LEFT);

            $membersCount = 2 + ($i % 6); // 2..7
            $members = [];
            $members[] = [
                'name' => $head,
                'date_of_birth' => $this->dobFromYears(28 + ($i % 25)),
                'relationship' => 'رب الأسرة',
                'gender' => $isMaleHead ? FamilyMember::GENDER_MALE : FamilyMember::GENDER_FEMALE,
            ];
            $members[] = [
                'name' => $isMaleHead
                    ? ($firstNamesFemale[($i + 3) % count($firstNamesFemale)].' '.$ln)
                    : ($firstNamesMale[($i + 4) % count($firstNamesMale)].' '.$ln),
                'date_of_birth' => $this->dobFromYears(22 + ($i % 20)),
                'relationship' => $isMaleHead ? 'زوجة' : 'زوج',
                'gender' => $isMaleHead ? FamilyMember::GENDER_FEMALE : FamilyMember::GENDER_MALE,
            ];

            for ($m = 3; $m <= $membersCount; $m++) {
                $isBoy = (($i + $m) % 2) === 0;
                $rel = $isBoy ? 'ابن' : 'ابنة';
                $age = max(0, (($i + $m) % 18)); // 0..17 (قد ينتج حديث ولادة)
                $members[] = [
                    'name' => ($isBoy
                        ? $firstNamesMale[($i + $m) % count($firstNamesMale)]
                        : $firstNamesFemale[($i + $m) % count($firstNamesFemale)]).' '.$ln,
                    'date_of_birth' => $age === 0 ? $this->dobNewborn() : $this->dobFromYears($age),
                    'relationship' => $rel,
                    'gender' => $isBoy ? FamilyMember::GENDER_MALE : FamilyMember::GENDER_FEMALE,
                ];
            }

            $demoFamilies[] = [
                'national_id' => $nid,
                'head_name' => $head,
                'phone' => $phone,
                'social_status' => $social[$i % count($social)],
                'financial_status' => $financial[$i % count($financial)],
                'file_status' => $fileStatuses[$i % count($fileStatuses)].' — '.$demoAreas[$i % count($demoAreas)],
                'members' => $members,
            ];
        }

        $familyModels = [];

        foreach ($demoFamilies as $row) {
            $user = User::query()->updateOrCreate(
                ['national_id' => $row['national_id']],
                [
                    'name' => $row['head_name'],
                    'email' => null,
                    'password' => 'init',
                    'role' => User::ROLE_FAMILY_HEAD,
                ]
            );
            $user->password = User::defaultSerialFromId($user->id);
            $user->save();

            $family = Family::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'head_name' => $row['head_name'],
                    'national_id' => $row['national_id'],
                    'phone' => $row['phone'],
                    'social_status' => $row['social_status'],
                    'financial_status' => $row['financial_status'],
                    'total_members' => 0,
                    'file_status' => $row['file_status'],
                ]
            );

            $family->members()->delete();
            foreach ($row['members'] as $m) {
                // توحيد: نريد تاريخ ميلاد، ونجعل age NULL حتى لا يصبح ثابتاً.
                $m['age'] = null;
                $family->members()->create($m);
            }
            $family->update(['total_members' => $family->members()->count()]);
            $familyModels[] = $family;
        }

        $firstFamily = $familyModels[0];

        // إنشاء 30 سجل فلترة (بأسماء مفهومة) + snapshots
        for ($i = 1; $i <= $TARGET_CAMP_FILTER_RECORDS; $i++) {
            $scope = $i % 3 === 0 ? 'members' : 'family';
            $criteria = ['filter_scope' => $scope];

            if ($scope === 'family') {
                $criteria['financial_status'] = $financial[$i % count($financial)];
                $criteria['members_min'] = ($i % 5) + 1; // 1..5
                $criteria['members_max'] = $criteria['members_min'] + 2;
                if ($i % 7 === 0) {
                    $criteria['has_newborn'] = true;
                }
            } else {
                $criteria['child_age_min'] = ($i % 6); // 0..5
                $criteria['child_age_max'] = $criteria['child_age_min'] + 5;
                $criteria['member_gender'] = $i % 2 === 0 ? 'male' : 'female';
                if ($i % 4 === 0) {
                    $criteria['member_relationships'] = ['ابن', 'ابنة'];
                }
            }

            $fake = \Illuminate\Http\Request::create('/', 'POST', $criteria);
            $families = Family::queryForAdminFilters($fake)->orderBy('id')->limit(120)->get();
            $snapshot = $this->buildCampFilterSnapshot($families);

            $recordName = $scope === 'family'
                ? sprintf(
                    'عائلات (%s) من %d إلى %d أفراد%s',
                    $criteria['financial_status'] === 'low' ? 'وضع مادي منخفض' : ($criteria['financial_status'] === 'medium' ? 'وضع مادي متوسط' : 'وضع مادي جيد'),
                    (int) $criteria['members_min'],
                    (int) $criteria['members_max'],
                    !empty($criteria['has_newborn']) ? ' + حديث ولادة' : ''
                )
                : sprintf(
                    'أفراد %s عمر %d–%d%s',
                    $criteria['member_gender'] === 'male' ? 'ذكور' : 'إناث',
                    (int) $criteria['child_age_min'],
                    (int) $criteria['child_age_max'],
                    !empty($criteria['member_relationships']) ? ' (أبناء/بنات)' : ''
                );

            CampFilterRecord::query()->updateOrCreate(
                ['user_id' => $admin->id, 'name' => $recordName],
                ['criteria' => $criteria, 'snapshot' => $snapshot]
            );
        }

        // نقرأ بعض السجلات لاستخدامها في توزيعات demo
        $campFilterLow = CampFilterRecord::query()
            ->where('user_id', $admin->id)
            ->where('name', 'like', '%01')
            ->first();
        $campFilterChildren = CampFilterRecord::query()
            ->where('user_id', $admin->id)
            ->where('name', 'like', '%03')
            ->first();
        $campFilterAll = CampFilterRecord::query()
            ->where('user_id', $admin->id)
            ->where('name', 'like', '%02')
            ->first();

        SiteSetting::putValue('footer_note', 'هذا عرض تجريبي — البيانات للاختبار فقط.');
        SiteSetting::putValue('office_hours', 'الأحد–الخميس: ٩ صباحاً – ٢ ظهراً');
        SiteSetting::putValue('map_embed_url', '');

        Distribution::query()->firstOrCreate(
            [
                'family_id' => $firstFamily->id,
                'package_type_id' => $food->id,
                'status' => Distribution::STATUS_PENDING,
                'camp_filter_record_id' => null,
            ],
            ['delivered_at' => null, 'administered_by' => null]
        );

        Distribution::query()->firstOrCreate(
            [
                'family_id' => $firstFamily->id,
                'package_type_id' => $hygiene->id,
                'status' => Distribution::STATUS_PENDING,
                'camp_filter_record_id' => null,
            ],
            ['delivered_at' => null, 'administered_by' => null]
        );

        Distribution::query()->firstOrCreate(
            [
                'family_id' => $firstFamily->id,
                'package_type_id' => $food->id,
                'status' => Distribution::STATUS_RECEIVED,
                'camp_filter_record_id' => null,
            ],
            [
                'delivered_at' => now()->subDays(2),
                'administered_by' => $admin->id,
            ]
        );

        if (isset($familyModels[1])) {
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[1]->id,
                    'package_type_id' => $school->id,
                    'status' => Distribution::STATUS_PENDING,
                    'camp_filter_record_id' => null,
                ],
                ['delivered_at' => null, 'administered_by' => null]
            );
        }

        if (isset($familyModels[2])) {
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[2]->id,
                    'package_type_id' => $food->id,
                    'status' => Distribution::STATUS_NOT_ELIGIBLE,
                    'camp_filter_record_id' => null,
                ],
                [
                    'delivered_at' => null,
                    'administered_by' => null,
                ]
            );
        }

        if (isset($familyModels[3])) {
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[3]->id,
                    'package_type_id' => $hygiene->id,
                    'status' => Distribution::STATUS_RECEIVED,
                    'camp_filter_record_id' => null,
                ],
                [
                    'delivered_at' => now()->subDays(1),
                    'administered_by' => $admin->id,
                ]
            );
        }

        if (isset($familyModels[4])) {
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[4]->id,
                    'package_type_id' => $winter->id,
                    'status' => Distribution::STATUS_PENDING,
                    'camp_filter_record_id' => $campFilterLow?->id,
                ],
                ['delivered_at' => null, 'administered_by' => null]
            );
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[4]->id,
                    'package_type_id' => $school->id,
                    'status' => Distribution::STATUS_RECEIVED,
                    'camp_filter_record_id' => $campFilterChildren?->id,
                ],
                [
                    'delivered_at' => now()->subDays(4),
                    'administered_by' => $admin->id,
                ]
            );
        }

        if (isset($familyModels[5])) {
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[5]->id,
                    'package_type_id' => $food->id,
                    'status' => Distribution::STATUS_PENDING,
                    'camp_filter_record_id' => $campFilterAll?->id,
                ],
                ['delivered_at' => null, 'administered_by' => null]
            );
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $familyModels[5]->id,
                    'package_type_id' => $hygiene->id,
                    'status' => Distribution::STATUS_PENDING,
                    'camp_filter_record_id' => null,
                ],
                ['delivered_at' => null, 'administered_by' => null]
            );
        }

        // توليد توزيعات إضافية حتى 30 سجل على الأقل (مع ربط بعضهم بسجلات فلترة)
        $distCreated = 0;
        foreach ($familyModels as $idx => $fam) {
            if ($distCreated >= $TARGET_DISTRIBUTIONS) {
                break;
            }
            $pt = $packageTypes[$idx % max(1, $packageTypes->count())] ?? $food;
            $status = $idx % 4 === 0 ? Distribution::STATUS_RECEIVED : Distribution::STATUS_PENDING;
            $recordId = $idx % 3 === 0 ? ($campFilterChildren?->id) : ($idx % 5 === 0 ? ($campFilterLow?->id) : null);

            Distribution::query()->create([
                'family_id' => $fam->id,
                'package_type_id' => $pt->id,
                'camp_filter_record_id' => $recordId,
                'status' => $status,
                'delivered_at' => $status === Distribution::STATUS_RECEIVED ? now()->subDays(($idx % 10) + 1) : null,
                'administered_by' => $admin->id,
            ]);
            $distCreated++;
        }

        $announcement1 = Announcement::query()->firstOrCreate(
            ['title' => 'توزيع طرود غذائية غداً'],
            [
                'content' => "السلام عليكم،\nتذكير بموعد توزيع الطرود الغذائية غداً بعد صلاة الظهر عند بوابة المخيم.\nشكراً لتعاونكم.",
                'admin_user_id' => $admin->id,
                'published_at' => now()->subDay(),
            ]
        );

        $announcement2 = Announcement::query()->firstOrCreate(
            ['title' => 'استبيان رضا عن الخدمات'],
            [
                'content' => 'نرجو تعبئة الاستبيان عند زيارة نقطة التوزيع لتحسين الخدمة.',
                'admin_user_id' => $admin->id,
                'published_at' => now()->subHours(5),
            ]
        );

        Announcement::query()->firstOrCreate(
            ['title' => 'تعليمات الاستلام للعائلات الجديدة'],
            [
                'content' => "يرجى إحضار الهوية وإثبات العنوان عند أول زيارة.\nالفريق في الخدمة من الساعة ١٠ صباحاً.",
                'admin_user_id' => $admin->id,
                'published_at' => now()->subHours(2),
            ]
        );

        // إعلانات إضافية حتى 30 (بنصوص مفهومة)
        $announcementTitles = [
            'تنبيه: تحديث موقع التسليم اليوم',
            'تذكير: إحضار رقم الدخول عند الاستلام',
            'ساعات عمل نقطة التوزيع هذا الأسبوع',
            'ملاحظة: أولوية للأسر ذات الأطفال',
            'تنظيم الدخول: الالتزام بالدور',
            'إرشادات السلامة داخل المخيم',
        ];
        for ($i = 1; $i <= $TARGET_ANNOUNCEMENTS; $i++) {
            $t = $announcementTitles[$i % count($announcementTitles)];
            Announcement::query()->firstOrCreate(
                ['title' => $t.' ('.($i).')'],
                [
                    'content' => "السلام عليكم،\n{$t}.\nشكراً لتعاونكم مع اللجنة.",
                    'admin_user_id' => $admin->id,
                    'published_at' => now()->subDays(($i % 14)),
                ]
            );
        }

        $announcements = Announcement::query()->orderBy('id')->get();

        $familyUser1 = User::query()->where('national_id', '2000000000')->first();
        if ($familyUser1) {
            Comment::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement1->id,
                    'user_id' => $familyUser1->id,
                    'body' => 'بارك الله في جهودكم، سنوافيكم في الموعد إن شاء الله.',
                ]
            );

            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement1->id,
                    'user_id' => $familyUser1->id,
                    'type' => AnnouncementReaction::TYPE_LIKE,
                ]
            );
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement1->id,
                    'user_id' => $familyUser1->id,
                    'type' => AnnouncementReaction::TYPE_THANKS,
                ]
            );
        }

        $familyUser2 = User::query()->where('national_id', '2100000001')->first();
        if ($familyUser2) {
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement1->id,
                    'user_id' => $familyUser2->id,
                    'type' => AnnouncementReaction::TYPE_INTERESTED,
                ]
            );
            Comment::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement2->id,
                    'user_id' => $familyUser2->id,
                    'body' => 'تم التعبئة اليوم، جزاكم الله خيراً.',
                ]
            );
        }

        $familyUser3 = User::query()->where('national_id', '2100000002')->first();
        if ($familyUser3) {
            Comment::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement2->id,
                    'user_id' => $familyUser3->id,
                    'body' => 'هل يوجد وقت إضافي لمن لم يستطع الحضور؟',
                ]
            );
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement2->id,
                    'user_id' => $familyUser3->id,
                    'type' => AnnouncementReaction::TYPE_LIKE,
                ]
            );
        }

        $familyUser4 = User::query()->where('national_id', '2100000004')->first();
        if ($familyUser4) {
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement1->id,
                    'user_id' => $familyUser4->id,
                    'type' => AnnouncementReaction::TYPE_THANKS,
                ]
            );
            Comment::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement1->id,
                    'user_id' => $familyUser4->id,
                    'body' => 'ننتظر التأكيد على البوابة الغربية إن أمكن.',
                ]
            );
        }

        $familyUser5 = User::query()->where('national_id', '2100000005')->first();
        if ($familyUser5) {
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement2->id,
                    'user_id' => $familyUser5->id,
                    'type' => AnnouncementReaction::TYPE_INTERESTED,
                ]
            );
        }

        // تعليقات وتفاعلات إضافية حتى 30 لكل جدول (باستخدام بعض مستخدمي العائلات)
        $familyUsers = User::query()
            ->where('role', User::ROLE_FAMILY_HEAD)
            ->orderBy('id')
            ->limit(40)
            ->get();

        for ($i = 1; $i <= $TARGET_COMMENTS; $i++) {
            $u = $familyUsers[($i - 1) % max(1, $familyUsers->count())] ?? $familyUser1;
            $a = $announcements[($i - 1) % max(1, $announcements->count())] ?? $announcement1;
            if (! $u || ! $a) {
                break;
            }
            Comment::query()->firstOrCreate(
                [
                    'announcement_id' => $a->id,
                    'user_id' => $u->id,
                    'body' => 'جزاكم الله خيراً. تم الاطلاع، ونأمل تسهيل الإجراءات.',
                ]
            );
        }

        $reactionTypes = [
            AnnouncementReaction::TYPE_LIKE,
            AnnouncementReaction::TYPE_INTERESTED,
            AnnouncementReaction::TYPE_THANKS,
        ];
        $reactionsCreated = 0;
        for ($i = 1; $i <= $TARGET_REACTIONS; $i++) {
            $u = $familyUsers[($i - 1) % max(1, $familyUsers->count())] ?? null;
            $a = $announcements[(($i - 1) * 2) % max(1, $announcements->count())] ?? null;
            if (! $u || ! $a) {
                break;
            }
            $type = $reactionTypes[$i % count($reactionTypes)];
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $a->id,
                    'user_id' => $u->id,
                    'type' => $type,
                ]
            );
            $reactionsCreated++;
        }

        $this->printCredentials();
    }

    /**
     * @param  Collection<int, Family>  $families
     * @return array<string, mixed>
     */
    private function buildCampFilterSnapshot(Collection $families): array
    {
        $families = $families->loadMissing('members')->values();
        $familyRows = $families->map(function (Family $f) {
            return [
                'id' => $f->id,
                'head_name' => $f->head_name,
                'national_id' => $f->national_id,
                'phone' => $f->phone,
                'social_status' => $f->social_status,
                'financial_status' => $f->financial_status,
                'total_members' => $f->total_members,
                'file_status' => $f->file_status,
                'members' => $f->members->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'age' => $m->age,
                    'gender' => $m->gender,
                    'relationship' => $m->relationship,
                ])->values()->all(),
            ];
        })->all();

        $count = count($familyRows);

        // received يجب أن يكون map (id => bool) وليس array مفهرس،
        // لأن الواجهة تحفظ الاستلام باستخدام familyId أو memberId.
        $received = [];
        foreach ($families as $f) {
            $received[(string) $f->id] = false;
            foreach ($f->members as $m) {
                $received[(string) $m->id] = false;
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'limit_applied' => 500,
            'families_count' => $count,
            'members_count' => $families->sum(fn (Family $f) => $f->members->count()),
            'received' => $received,
            'families' => $familyRows,
        ];
    }

    private function printCredentials(): void
    {
        $lines = [
            '',
            '=== بيانات تجريبية (تَكافل) ===',
            'مسؤول (بريد + كلمة مرور — صفحة دخول الإدارة): admin@taiba.local | AdminDemo123!',
            'العائلات: الرقم التسلسلي = 00 + id المستخدم (أرقام فقط).',
            '',
        ];

        $nationalIds = ['2000000000', '2100000001', '2100000002', '2100000003', '2100000004', '2100000005'];
        foreach ($nationalIds as $nid) {
            $u = User::query()->where('national_id', $nid)->first();
            if ($u) {
                $lines[] = sprintf(
                    'هوية %s | الرقم التسلسلي: %s (id=%d)',
                    $nid,
                    User::defaultSerialFromId($u->id),
                    $u->id
                );
            }
        }

        $lines[] = '================================';
        $lines[] = '';

        foreach ($lines as $line) {
            if ($this->command) {
                $this->command->line($line);
            }
        }
    }
}
