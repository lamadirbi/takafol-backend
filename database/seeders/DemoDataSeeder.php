<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AnnouncementReaction;
use App\Models\Camp;
use App\Models\CampFilterRecord;
use App\Models\ChangeRequest;
use App\Models\Comment;
use App\Models\Distribution;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\PackageType;
use App\Models\SiteSetting;
use App\Models\SubscriptionRenewalRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * بيانات تجريبية كاملة لكل جداول المنصة.
 * التشغيل: php artisan migrate:fresh --seed
 */
class DemoDataSeeder extends Seeder
{
    /** @var list<array{family: Family, user: User, national_id: string}> */
    private array $featuredLogins = [];

    public function run(): void
    {
        $this->setTenant(null);

        $this->seedSuperAdmin();
        $camps = $this->seedCamps();
        $this->call(CampRegistrationRequestSeeder::class);

        $this->seedCampWorld($camps['taiba'], $this->taibaFamilies(), [
            'primary' => [
                'national_id' => '1000000000',
                'username' => '100100',
                'password' => '200200',
                'name' => 'لجنة المخيم — مسؤول رئيسي',
                'is_super' => true,
            ],
            'extras' => [
                [
                    'national_id' => '1000000001',
                    'username' => 'taiba2',
                    'password' => '123456',
                    'name' => 'سارة النجار — مسؤولة توزيع',
                    'is_super' => false,
                ],
            ],
        ], true);

        $this->seedCampWorld($camps['gaza'], $this->gazaFamilies(), [
            'primary' => [
                'national_id' => '1000000002',
                'username' => '200100',
                'password' => '200200',
                'name' => 'مدير مخيم غزة الصمود',
                'is_super' => false,
            ],
            'extras' => [],
        ], false);

        $this->seedCampWorld($camps['north'], $this->northFamilies(), [
            'primary' => [
                'national_id' => '1000000003',
                'username' => '300100',
                'password' => '200200',
                'name' => 'مدير مخيم شمال غزة',
                'is_super' => false,
            ],
            'extras' => [],
        ], false);

        $this->printCredentials($camps);
    }

    private function seedSuperAdmin(): void
    {
        $now = now();
        DB::table('users')->updateOrInsert(
            ['national_id' => '0000000000'],
            [
                'name' => 'Super Administrator',
                'username' => 'superadmin',
                'email' => 'super@taiba.local',
                'password' => Hash::make('SuperPassword123!'),
                'role' => User::ROLE_ADMIN,
                'is_super' => true,
                'camp_id' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    /**
     * @return array{taiba: Camp, gaza: Camp, north: Camp}
     */
    private function seedCamps(): array
    {
        $taiba = Camp::query()->updateOrCreate(
            ['slug' => 'taiba'],
            [
                'name' => 'مخيم طيبة التربوي',
                'logo_path' => null,
                'is_active' => true,
                'subscription_valid_until' => now()->addMonths(4)->toDateString(),
                'payment_notification_whatsapp' => '0591112233',
                'landing_page_data' => [
                    'hero_title' => 'نعمل معاً لتنظيم المساعدات… كرامة، شفافية، وأمل',
                    'hero_description' => 'منصة تَكافل تساعد اللجنة والعائلات في مخيم طيبة على متابعة الطرود والتواصل بسهولة.',
                ],
            ]
        );

        $gaza = Camp::query()->updateOrCreate(
            ['slug' => 'gaza'],
            [
                'name' => 'مخيم غزة الصمود',
                'logo_path' => null,
                'is_active' => true,
                'subscription_valid_until' => now()->addMonth()->toDateString(),
                'payment_notification_whatsapp' => '0592223344',
                'landing_page_data' => [
                    'hero_title' => 'معاً لأجل غزة… شفافية وأمل',
                    'hero_description' => 'منصة لتنظيم توزيع المساعدات في مخيم غزة الصمود.',
                ],
            ]
        );

        $north = Camp::query()->updateOrCreate(
            ['slug' => 'north-gaza'],
            [
                'name' => 'مخيم شمال غزة',
                'logo_path' => null,
                'is_active' => true,
                'subscription_valid_until' => now()->subDays(5)->toDateString(),
                'payment_notification_whatsapp' => '0593334455',
                'landing_page_data' => [
                    'hero_title' => 'مخيم شمال غزة',
                    'hero_description' => 'اشتراك المخيم بحاجة إلى تجديد. دخول العائلات متوقف حتى يُحدَّث تاريخ الصلاحية.',
                ],
            ]
        );

        return compact('taiba', 'gaza', 'north');
    }

    /**
     * @param  list<array<string, mixed>>  $familyRows
     * @param  array{primary: array<string, mixed>, extras: list<array<string, mixed>>}  $admins
     */
    private function seedCampWorld(Camp $camp, array $familyRows, array $admins, bool $featured): void
    {
        $this->setTenant($camp);

        $primary = $this->seedAdmin($camp, $admins['primary']);
        $camp->update(['primary_admin_user_id' => $primary->id]);

        foreach ($admins['extras'] as $extra) {
            $this->seedAdmin($camp, $extra);
        }

        $this->seedSiteSettings($camp);
        $packages = $this->seedPackageTypes($camp);
        $families = $this->seedFamilies($camp, $familyRows, $featured);
        $records = $this->seedFilterRecords($camp, $primary, $families);
        $this->seedDistributions($camp, $primary, $families, $packages, $records);
        $this->seedAnnouncements($camp, $primary, $families);
        $this->seedChangeRequests($camp, $primary, $families);
        $this->seedRenewalRequests($camp, $primary);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedAdmin(Camp $camp, array $data): User
    {
        $admin = User::withoutGlobalScopes()->updateOrCreate(
            ['national_id' => $data['national_id']],
            [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => null,
                'password' => $data['password'],
                'role' => User::ROLE_ADMIN,
                'is_super' => (bool) ($data['is_super'] ?? false),
                'camp_id' => $camp->id,
            ]
        );

        if ($admin->username !== $data['username']) {
            $admin->username = $data['username'];
            $admin->save();
        }

        return $admin;
    }

    private function seedSiteSettings(Camp $camp): void
    {
        SiteSetting::putValue('camp_name', $camp->name);
        SiteSetting::putValue('support_phone', $camp->payment_notification_whatsapp ?: '0590000000');
        SiteSetting::putValue('support_note', 'للاستفسار تواصل مع لجنة '.$camp->name.' عبر الواتساب في أوقات الدوام.');
    }

    /**
     * @return Collection<int, PackageType>
     */
    private function seedPackageTypes(Camp $camp): Collection
    {
        $defs = [
            ['name' => 'طرد غذائي', 'description' => 'مواد غذائية أساسية للأسرة.'],
            ['name' => 'طرد نظافة', 'description' => 'مواد نظافة شخصية ومنزلية.'],
            ['name' => 'طرد مدرسي', 'description' => 'قرطاسية ومستلزمات طلاب.'],
            ['name' => 'طرد شتوي', 'description' => 'أغطية وملابس شتوية.'],
            ['name' => 'حليب أطفال', 'description' => 'حليب لحديثي الولادة والرضّع.'],
        ];

        $types = collect();
        foreach ($defs as $def) {
            $types->push(PackageType::query()->updateOrCreate(
                ['name' => $def['name'], 'camp_id' => $camp->id],
                ['description' => $def['description'], 'camp_id' => $camp->id]
            ));
        }

        return $types;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, Family>
     */
    private function seedFamilies(Camp $camp, array $rows, bool $featured): Collection
    {
        $models = collect();

        foreach ($rows as $index => $row) {
            $user = User::withoutGlobalScopes()->updateOrCreate(
                ['national_id' => $row['national_id']],
                [
                    'name' => $row['head_name'],
                    'email' => null,
                    'username' => null,
                    'password' => 'init',
                    'role' => User::ROLE_FAMILY_HEAD,
                    'is_super' => false,
                    'camp_id' => $camp->id,
                ]
            );
            $user->password = User::defaultSerialFromId((int) $user->id);
            $user->save();

            $family = Family::withoutGlobalScopes()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'head_name' => $row['head_name'],
                    'head_gender' => $row['head_gender'] ?? FamilyMember::GENDER_MALE,
                    'national_id' => $row['national_id'],
                    'phone' => $row['phone'],
                    'social_status' => $row['social_status'],
                    'financial_status' => $row['financial_status'],
                    'spouse_name' => $row['spouse_name'] ?? null,
                    'spouse_national_id' => $row['spouse_national_id'] ?? null,
                    'file_status' => $row['file_status'],
                    'original_governorate' => $row['original_governorate'] ?? null,
                    'original_neighborhood' => $row['original_neighborhood'] ?? null,
                    'total_members' => 0,
                    'camp_id' => $camp->id,
                ]
            );

            $family->members()->delete();
            foreach ($row['members'] as $member) {
                $family->members()->create([
                    'name' => $member['name'],
                    'age' => null,
                    'date_of_birth' => $member['date_of_birth'],
                    'relationship' => $member['relationship'],
                    'gender' => $member['gender'],
                    'camp_id' => $camp->id,
                ]);
            }
            $family->update(['total_members' => $family->members()->count()]);
            $family = $family->fresh(['members', 'user']);
            $models->push($family);

            if ($featured && $index < 6) {
                $this->featuredLogins[] = [
                    'family' => $family,
                    'user' => $user,
                    'national_id' => $row['national_id'],
                ];
            }
        }

        return $models;
    }

    /**
     * @param  Collection<int, Family>  $families
     * @return Collection<int, CampFilterRecord>
     */
    private function seedFilterRecords(Camp $camp, User $admin, Collection $families): Collection
    {
        $defs = [
            [
                'name' => 'عائلات وضع مادي منخفض',
                'criteria' => ['filter_scope' => 'family', 'financial_status' => 'low'],
            ],
            [
                'name' => 'عائلات لديها حديث ولادة',
                'criteria' => ['filter_scope' => 'family', 'has_newborn' => true],
            ],
            [
                'name' => 'أطفال ذكور حتى 5 سنوات',
                'criteria' => [
                    'filter_scope' => 'members',
                    'member_gender' => 'male',
                    'child_age_min' => 0,
                    'child_age_max' => 5,
                    'member_relationships' => ['ابن'],
                ],
            ],
            [
                'name' => 'أسر متوسطة من 3 إلى 6 أفراد',
                'criteria' => [
                    'filter_scope' => 'family',
                    'financial_status' => 'medium',
                    'members_min' => 3,
                    'members_max' => 6,
                ],
            ],
            [
                'name' => 'إناث عمر 6–12 (بنات)',
                'criteria' => [
                    'filter_scope' => 'members',
                    'member_gender' => 'female',
                    'child_age_min' => 6,
                    'child_age_max' => 12,
                    'member_relationships' => ['ابنة'],
                ],
            ],
        ];

        $records = collect();
        foreach ($defs as $i => $def) {
            $snapshot = $this->buildCampFilterSnapshot($def['criteria']);
            if ($i === 0 && $families->isNotEmpty()) {
                $snapshot['active_package_label'] = 'طرد غذائي — أغسطس 2026';
                $snapshot['sent_package_labels'] = ['طرد غذائي — يوليو 2026'];
                $first = $families->first();
                $snapshot['received_ids'] = [(string) $first->id];
            }

            $records->push(CampFilterRecord::query()->updateOrCreate(
                ['user_id' => $admin->id, 'name' => $def['name'], 'camp_id' => $camp->id],
                ['criteria' => $def['criteria'], 'snapshot' => $snapshot, 'camp_id' => $camp->id]
            ));
        }

        return $records;
    }

    /**
     * @param  Collection<int, Family>  $families
     * @param  Collection<int, PackageType>  $packages
     * @param  Collection<int, CampFilterRecord>  $records
     */
    private function seedDistributions(Camp $camp, User $admin, Collection $families, Collection $packages, Collection $records): void
    {
        if ($families->isEmpty() || $packages->isEmpty()) {
            return;
        }

        $food = $packages->firstWhere('name', 'طرد غذائي') ?? $packages->first();
        $hygiene = $packages->firstWhere('name', 'طرد نظافة') ?? $packages->first();
        $school = $packages->firstWhere('name', 'طرد مدرسي') ?? $packages->first();
        $winter = $packages->firstWhere('name', 'طرد شتوي') ?? $packages->first();
        $milk = $packages->firstWhere('name', 'حليب أطفال') ?? $packages->first();
        $lowRecord = $records->firstWhere('name', 'عائلات وضع مادي منخفض');
        $newbornRecord = $records->firstWhere('name', 'عائلات لديها حديث ولادة');

        $plans = [];
        foreach ($families as $idx => $family) {
            $plans[] = [
                'family_id' => $family->id,
                'package_type_id' => $food->id,
                'package_label' => 'طرد غذائي — أغسطس 2026',
                'camp_filter_record_id' => $lowRecord?->id,
                'status' => $idx % 3 === 0 ? Distribution::STATUS_RECEIVED : Distribution::STATUS_PENDING,
            ];
            if ($idx % 2 === 0) {
                $plans[] = [
                    'family_id' => $family->id,
                    'package_type_id' => $hygiene->id,
                    'package_label' => 'طرد نظافة — أغسطس 2026',
                    'camp_filter_record_id' => null,
                    'status' => $idx % 4 === 0 ? Distribution::STATUS_RECEIVED : Distribution::STATUS_PENDING,
                ];
            }
            if ($idx === 1) {
                $plans[] = [
                    'family_id' => $family->id,
                    'package_type_id' => $school->id,
                    'package_label' => 'طرد مدرسي — الفصل الأول',
                    'camp_filter_record_id' => null,
                    'status' => Distribution::STATUS_PENDING,
                ];
            }
            if ($idx === 2) {
                $plans[] = [
                    'family_id' => $family->id,
                    'package_type_id' => $food->id,
                    'package_label' => 'طرد غذائي — يوليو 2026',
                    'camp_filter_record_id' => null,
                    'status' => Distribution::STATUS_NOT_ELIGIBLE,
                ];
            }
            if ($idx === 4) {
                $plans[] = [
                    'family_id' => $family->id,
                    'package_type_id' => $winter->id,
                    'package_label' => 'طرد شتوي 2026',
                    'camp_filter_record_id' => $lowRecord?->id,
                    'status' => Distribution::STATUS_PENDING,
                ];
            }
            if ($idx === 0) {
                $plans[] = [
                    'family_id' => $family->id,
                    'package_type_id' => $milk->id,
                    'package_label' => 'حليب أطفال — أغسطس',
                    'camp_filter_record_id' => $newbornRecord?->id,
                    'status' => Distribution::STATUS_RECEIVED,
                ];
            }
        }

        foreach ($plans as $plan) {
            $status = $plan['status'];
            Distribution::query()->firstOrCreate(
                [
                    'family_id' => $plan['family_id'],
                    'package_label' => $plan['package_label'],
                    'status' => $status,
                    'camp_id' => $camp->id,
                ],
                [
                    'package_type_id' => $plan['package_type_id'],
                    'camp_filter_record_id' => $plan['camp_filter_record_id'],
                    'delivered_at' => $status === Distribution::STATUS_RECEIVED ? now()->subDays(random_int(1, 10)) : null,
                    'administered_by' => $admin->id,
                    'camp_id' => $camp->id,
                ]
            );
        }
    }

    /**
     * @param  Collection<int, Family>  $families
     */
    private function seedAnnouncements(Camp $camp, User $admin, Collection $families): void
    {
        $defs = [
            [
                'title' => 'توزيع طرود غذائية غداً',
                'content' => "السلام عليكم،\nتذكير بموعد توزيع الطرود الغذائية غداً بعد صلاة الظهر عند بوابة المخيم.\nيرجى إحضار الهوية والرقم التسلسلي.\nشكراً لتعاونكم.",
                'published_at' => now()->subDay(),
            ],
            [
                'title' => 'استبيان رضا عن الخدمات',
                'content' => 'نرجو تعبئة الاستبيان عند زيارة نقطة التوزيع لتحسين الخدمة. رأيكم يهمنا.',
                'published_at' => now()->subHours(5),
            ],
            [
                'title' => 'تعليمات الاستلام للعائلات الجديدة',
                'content' => "يرجى إحضار الهوية وإثبات العنوان عند أول زيارة.\nالفريق في الخدمة من الساعة ١٠ صباحاً حتى ٢ ظهراً.",
                'published_at' => now()->subHours(2),
            ],
            [
                'title' => 'تنبيه: تحديث موقع التسليم اليوم',
                'content' => 'سيكون التسليم من الجهة الغربية للمخيم بسبب أعمال صيانة عند البوابة الرئيسية.',
                'published_at' => now()->subDays(3),
            ],
            [
                'title' => 'ساعات عمل نقطة التوزيع هذا الأسبوع',
                'content' => 'الأحد–الخميس: ٩ صباحاً – ٢ ظهراً. الجمعة والسبت إجازة.',
                'published_at' => now()->subDays(6),
            ],
            [
                'title' => 'أولوية للأسر ذات حديثي الولادة',
                'content' => 'عند توزيع حليب الأطفال تُعطى الأولوية للأسر المسجّل لديها مولود أقل من سنة.',
                'published_at' => now()->subDays(8),
            ],
        ];

        $announcements = collect();
        foreach ($defs as $def) {
            $announcements->push(Announcement::query()->firstOrCreate(
                ['title' => $def['title'], 'admin_user_id' => $admin->id, 'camp_id' => $camp->id],
                [
                    'content' => $def['content'],
                    'published_at' => $def['published_at'],
                    'camp_id' => $camp->id,
                ]
            ));
        }

        $familyUsers = $families->map->user->filter()->values();
        if ($familyUsers->isEmpty() || $announcements->isEmpty()) {
            return;
        }

        $comments = [
            'بارك الله في جهودكم، سنوافيكم في الموعد إن شاء الله.',
            'تم التعبئة اليوم، جزاكم الله خيراً.',
            'هل يوجد وقت إضافي لمن لم يستطع الحضور؟',
            'ننتظر التأكيد على البوابة الغربية إن أمكن.',
            'تم الاطلاع، ونأمل تسهيل الإجراءات.',
            'شكراً للتوضيح بخصوص حديثي الولادة.',
        ];
        $types = [
            AnnouncementReaction::TYPE_LIKE,
            AnnouncementReaction::TYPE_INTERESTED,
            AnnouncementReaction::TYPE_THANKS,
        ];

        foreach ($familyUsers as $i => $user) {
            $announcement = $announcements[$i % $announcements->count()];
            Comment::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement->id,
                    'user_id' => $user->id,
                    'body' => $comments[$i % count($comments)],
                    'camp_id' => $camp->id,
                ]
            );
            AnnouncementReaction::query()->firstOrCreate(
                [
                    'announcement_id' => $announcement->id,
                    'user_id' => $user->id,
                    'type' => $types[$i % count($types)],
                    'camp_id' => $camp->id,
                ]
            );
        }
    }

    /**
     * @param  Collection<int, Family>  $families
     */
    private function seedChangeRequests(Camp $camp, User $admin, Collection $families): void
    {
        $withMembers = $families->filter(fn (Family $f) => $f->members->isNotEmpty())->values();
        if ($withMembers->count() < 2) {
            return;
        }

        $pendingFamily = $withMembers[0];
        $updateFamily = $withMembers[1] ?? $pendingFamily;
        $approvedFamily = $withMembers[2] ?? $pendingFamily;
        $rejectedFamily = $withMembers[3] ?? $updateFamily;
        $cancelledFamily = $withMembers[4] ?? $rejectedFamily;

        $childToUpdate = $updateFamily->members->first(fn ($m) => $m->relationship !== 'رب الأسرة')
            ?? $updateFamily->members->first();

        $defs = [
            [
                'family' => $pendingFamily,
                'status' => ChangeRequest::STATUS_PENDING,
                'payload' => [
                    'family' => [
                        'phone' => '0598881111',
                        'original_neighborhood' => 'حي الأمل — مبنى 4',
                    ],
                    'members' => [
                        'add' => [[
                            'name' => 'مولود جديد',
                            'relationship' => 'ابن',
                            'gender' => FamilyMember::GENDER_MALE,
                            'date_of_birth' => now()->subMonths(2)->toDateString(),
                        ]],
                    ],
                ],
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
            [
                'family' => $updateFamily,
                'status' => ChangeRequest::STATUS_PENDING,
                'payload' => [
                    'family' => [
                        'social_status' => $updateFamily->social_status ?: 'married',
                    ],
                    'members' => [
                        'update' => $childToUpdate ? [[
                            'id' => $childToUpdate->id,
                            'name' => $childToUpdate->name,
                            'date_of_birth' => now()->subYears(max(1, (int) ($childToUpdate->age ?: 8)))->toDateString(),
                        ]] : [],
                    ],
                ],
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
            [
                'family' => $approvedFamily,
                'status' => ChangeRequest::STATUS_APPROVED,
                'payload' => [
                    'family' => [
                        'phone' => $approvedFamily->phone,
                    ],
                ],
                'review_note' => 'تم التدقيق والموافقة.',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now()->subDays(2),
            ],
            [
                'family' => $rejectedFamily,
                'status' => ChangeRequest::STATUS_REJECTED,
                'payload' => [
                    'family' => [
                        'head_name' => $rejectedFamily->head_name.' (تعديل مرفوض)',
                    ],
                ],
                'review_note' => 'الاسم لا يطابق الهوية. يُعاد الطلب بمرفق أوضح.',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now()->subDays(1),
            ],
            [
                'family' => $cancelledFamily,
                'status' => ChangeRequest::STATUS_CANCELLED,
                'payload' => [
                    'members' => [
                        'delete' => [],
                    ],
                ],
                'review_note' => 'أُلغي من جهة العائلة.',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
        ];

        foreach ($defs as $def) {
            /** @var Family $family */
            $family = $def['family'];
            ChangeRequest::query()->firstOrCreate(
                [
                    'family_id' => $family->id,
                    'status' => $def['status'],
                    'type' => 'family_profile',
                    'camp_id' => $camp->id,
                ],
                [
                    'requested_by' => $family->user_id,
                    'reviewed_by' => $def['reviewed_by'],
                    'payload' => $def['payload'],
                    'review_note' => $def['review_note'],
                    'reviewed_at' => $def['reviewed_at'],
                    'camp_id' => $camp->id,
                ]
            );
        }
    }

    private function seedRenewalRequests(Camp $camp, User $admin): void
    {
        $rows = [
            [
                'status' => SubscriptionRenewalRequest::STATUS_APPROVED,
                'admin_note' => 'تم التأكيد وتحديث تاريخ الاشتراك.',
            ],
            [
                'status' => SubscriptionRenewalRequest::STATUS_PENDING,
                'admin_note' => null,
            ],
            [
                'status' => SubscriptionRenewalRequest::STATUS_REJECTED,
                'admin_note' => 'صورة التحويل غير واضحة. يُعاد الإرسال.',
            ],
        ];

        foreach ($rows as $row) {
            SubscriptionRenewalRequest::query()->firstOrCreate(
                [
                    'camp_id' => $camp->id,
                    'status' => $row['status'],
                ],
                [
                    'admin_user_id' => $admin->id,
                    'image_path' => null,
                    'admin_note' => $row['admin_note'],
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function buildCampFilterSnapshot(array $criteria): array
    {
        $fake = Request::create('/', 'POST', $criteria);
        $families = Family::queryForAdminFilters($fake)->orderBy('id')->limit(120)->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'limit_applied' => 500,
            'families_count' => $families->count(),
            'members_count' => $families->sum(fn (Family $f) => $f->members->count()),
            'received_ids' => [],
            'active_package_label' => null,
            'notify_locked' => false,
            'sent_package_labels' => [],
            'families' => $families->map(function (Family $f) {
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
            })->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taibaFamilies(): array
    {
        $named = [
            [
                'national_id' => '2000000000',
                'head_name' => 'محمد أحمد منصور',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0591111111',
                'social_status' => 'married',
                'financial_status' => 'low',
                'file_status' => 'جاري',
                'spouse_name' => 'سارة خالد منصور',
                'spouse_national_id' => '2900000000',
                'original_governorate' => 'غزة',
                'original_neighborhood' => 'الشجاعية',
                'members' => [
                    ['name' => 'محمد أحمد منصور', 'date_of_birth' => $this->dobFromYears(38), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'سارة خالد منصور', 'date_of_birth' => $this->dobFromYears(32), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'ليان محمد منصور', 'date_of_birth' => $this->dobFromYears(8), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'يوسف محمد منصور', 'date_of_birth' => $this->dobFromYears(3), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'آدم محمد منصور', 'date_of_birth' => $this->dobNewborn(), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
            [
                'national_id' => '2100000001',
                'head_name' => 'خالد العتيبي',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0592222222',
                'social_status' => 'married',
                'financial_status' => 'medium',
                'file_status' => 'مكتمل',
                'spouse_name' => 'نورة سعد العتيبي',
                'spouse_national_id' => '2900000001',
                'original_governorate' => 'خان يونس',
                'original_neighborhood' => 'حي الأمل',
                'members' => [
                    ['name' => 'خالد العتيبي', 'date_of_birth' => $this->dobFromYears(45), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'نورة سعد العتيبي', 'date_of_birth' => $this->dobFromYears(40), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'سالم خالد العتيبي', 'date_of_birth' => $this->dobFromYears(4), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'فاطمة أم خالد', 'date_of_birth' => $this->dobFromYears(68), 'relationship' => 'أم', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000002',
                'head_name' => 'فاطمة الزهراني',
                'head_gender' => FamilyMember::GENDER_FEMALE,
                'phone' => '0593333333',
                'social_status' => 'widowed',
                'financial_status' => 'low',
                'file_status' => 'جاري',
                'spouse_name' => null,
                'spouse_national_id' => null,
                'original_governorate' => 'رفح',
                'original_neighborhood' => 'تل السلطان',
                'members' => [
                    ['name' => 'فاطمة الزهراني', 'date_of_birth' => $this->dobFromYears(52), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'رنا محمود الزهراني', 'date_of_birth' => $this->dobFromYears(19), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'هدى محمود الزهراني', 'date_of_birth' => $this->dobFromYears(2), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000003',
                'head_name' => 'عبدالله الشهري',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0594444444',
                'social_status' => 'divorced',
                'financial_status' => 'low',
                'file_status' => 'معلق',
                'original_governorate' => 'شمال غزة',
                'original_neighborhood' => 'جباليا',
                'members' => [
                    ['name' => 'عبدالله الشهري', 'date_of_birth' => $this->dobFromYears(29), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
            [
                'national_id' => '2100000004',
                'head_name' => 'ناصر القحطاني',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0595555555',
                'social_status' => 'married',
                'financial_status' => 'medium',
                'file_status' => 'جاري',
                'spouse_name' => 'هند فهد القحطاني',
                'spouse_national_id' => '2900000004',
                'original_governorate' => 'غزة',
                'original_neighborhood' => 'الرمال',
                'members' => [
                    ['name' => 'ناصر القحطاني', 'date_of_birth' => $this->dobFromYears(41), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'هند فهد القحطاني', 'date_of_birth' => $this->dobFromYears(36), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'عمر ناصر القحطاني', 'date_of_birth' => $this->dobFromYears(12), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'دانة ناصر القحطاني', 'date_of_birth' => $this->dobFromYears(9), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'لولوة ناصر القحطاني', 'date_of_birth' => $this->dobFromYears(6), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000005',
                'head_name' => 'منى الغامدي',
                'head_gender' => FamilyMember::GENDER_FEMALE,
                'phone' => '0596666666',
                'social_status' => 'abandoned',
                'financial_status' => 'low',
                'file_status' => 'مكتمل',
                'original_governorate' => 'الوسطى',
                'original_neighborhood' => 'النصيرات',
                'members' => [
                    ['name' => 'منى الغامدي', 'date_of_birth' => $this->dobFromYears(44), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'سعد علي الغامدي', 'date_of_birth' => $this->dobFromYears(16), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'أمل علي الغامدي', 'date_of_birth' => $this->dobFromYears(11), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000006',
                'head_name' => 'سعيد الحربي',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0597777777',
                'social_status' => 'married',
                'financial_status' => 'good',
                'file_status' => 'مكتمل',
                'spouse_name' => 'ريم عبدالله الحربي',
                'spouse_national_id' => '2900000006',
                'original_governorate' => 'غزة',
                'original_neighborhood' => 'حي النصر',
                'members' => [
                    ['name' => 'سعيد الحربي', 'date_of_birth' => $this->dobFromYears(50), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'ريم عبدالله الحربي', 'date_of_birth' => $this->dobFromYears(46), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'فيصل سعيد الحربي', 'date_of_birth' => $this->dobFromYears(22), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'عبدالله سعيد الحربي', 'date_of_birth' => $this->dobFromYears(74), 'relationship' => 'أب', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'خديجة سعيد', 'date_of_birth' => $this->dobFromYears(70), 'relationship' => 'جدة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '2100000007',
                'head_name' => 'أمل التميمي',
                'head_gender' => FamilyMember::GENDER_FEMALE,
                'phone' => '0598888888',
                'social_status' => 'widowed',
                'financial_status' => 'medium',
                'file_status' => 'جاري',
                'original_governorate' => 'خان يونس',
                'original_neighborhood' => 'بني سهيلا',
                'members' => [
                    ['name' => 'أمل التميمي', 'date_of_birth' => $this->dobFromYears(36), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'كريم وليد التميمي', 'date_of_birth' => $this->dobFromYears(10), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'تالا وليد التميمي', 'date_of_birth' => $this->dobFromYears(7), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'لينا وليد التميمي', 'date_of_birth' => $this->dobNewborn(), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
        ];

        return array_merge($named, $this->generatedFamilies(12, 2200000000, 590010000, 'taiba'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gazaFamilies(): array
    {
        $named = [
            [
                'national_id' => '3000000000',
                'head_name' => 'إبراهيم أبو العطا',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0592010001',
                'social_status' => 'married',
                'financial_status' => 'low',
                'file_status' => 'جاري',
                'spouse_name' => 'هدى إبراهيم أبو العطا',
                'spouse_national_id' => '3900000000',
                'original_governorate' => 'غزة',
                'original_neighborhood' => 'الزيتون',
                'members' => [
                    ['name' => 'إبراهيم أبو العطا', 'date_of_birth' => $this->dobFromYears(42), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'هدى إبراهيم أبو العطا', 'date_of_birth' => $this->dobFromYears(37), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'ماجد إبراهيم أبو العطا', 'date_of_birth' => $this->dobFromYears(14), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'ياسمين إبراهيم أبو العطا', 'date_of_birth' => $this->dobFromYears(9), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'نور إبراهيم أبو العطا', 'date_of_birth' => $this->dobNewborn(), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
            [
                'national_id' => '3000000001',
                'head_name' => 'سمية النجار',
                'head_gender' => FamilyMember::GENDER_FEMALE,
                'phone' => '0592010002',
                'social_status' => 'widowed',
                'financial_status' => 'low',
                'file_status' => 'مكتمل',
                'original_governorate' => 'غزة',
                'original_neighborhood' => 'الشجاعية',
                'members' => [
                    ['name' => 'سمية النجار', 'date_of_birth' => $this->dobFromYears(48), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'أنس سليم النجار', 'date_of_birth' => $this->dobFromYears(17), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'رند سليم النجار', 'date_of_birth' => $this->dobFromYears(13), 'relationship' => 'ابنة', 'gender' => FamilyMember::GENDER_FEMALE],
                ],
            ],
        ];

        return array_merge($named, $this->generatedFamilies(8, 3100000000, 592010000, 'gaza'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function northFamilies(): array
    {
        return array_merge([
            [
                'national_id' => '4000000000',
                'head_name' => 'حسن أبو حصيرة',
                'head_gender' => FamilyMember::GENDER_MALE,
                'phone' => '0593010001',
                'social_status' => 'married',
                'financial_status' => 'low',
                'file_status' => 'جاري',
                'spouse_name' => 'إيمان حسن أبو حصيرة',
                'spouse_national_id' => '4900000000',
                'original_governorate' => 'شمال غزة',
                'original_neighborhood' => 'بيت حانون',
                'members' => [
                    ['name' => 'حسن أبو حصيرة', 'date_of_birth' => $this->dobFromYears(39), 'relationship' => 'رب الأسرة', 'gender' => FamilyMember::GENDER_MALE],
                    ['name' => 'إيمان حسن أبو حصيرة', 'date_of_birth' => $this->dobFromYears(34), 'relationship' => 'زوجة', 'gender' => FamilyMember::GENDER_FEMALE],
                    ['name' => 'كريم حسن أبو حصيرة', 'date_of_birth' => $this->dobFromYears(6), 'relationship' => 'ابن', 'gender' => FamilyMember::GENDER_MALE],
                ],
            ],
        ], $this->generatedFamilies(5, 4100000000, 593010000, 'north'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generatedFamilies(int $count, int $baseNid, int $basePhone, string $prefix): array
    {
        $social = ['married', 'widowed', 'divorced', 'abandoned', 'married'];
        $financial = ['low', 'medium', 'good'];
        $fileStatuses = ['جاري', 'مكتمل', 'معلق'];
        $firstNamesMale = ['أحمد', 'محمود', 'خالد', 'يوسف', 'عمر', 'سليم', 'فادي', 'باسم', 'رامي', 'طارق'];
        $firstNamesFemale = ['نورة', 'هدى', 'رنا', 'آية', 'لينا', 'داليا', 'مها', 'إيمان', 'سلمى', 'جنى'];
        $lastNames = ['الغول', 'العكلوك', 'حمدان', 'الشريف', 'أبو وردة', 'الدهشان', 'العصار', 'النخالة', 'صيام', 'البربار'];
        $govs = ['غزة', 'شمال غزة', 'خان يونس', 'رفح', 'الوسطى'];
        $hoods = ['الشجاعية', 'الرمال', 'جباليا', 'النصيرات', 'البريج', 'تل السلطان', 'بني سهيلا', 'حي الأمل'];

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $isMaleHead = $i % 2 === 0;
            $ln = $lastNames[$i % count($lastNames)];
            $fn = $isMaleHead ? $firstNamesMale[$i % count($firstNamesMale)] : $firstNamesFemale[$i % count($firstNamesFemale)];
            $head = $fn.' '.$ln;
            $membersCount = 2 + ($i % 5);
            $members = [[
                'name' => $head,
                'date_of_birth' => $this->dobFromYears(28 + ($i % 25)),
                'relationship' => 'رب الأسرة',
                'gender' => $isMaleHead ? FamilyMember::GENDER_MALE : FamilyMember::GENDER_FEMALE,
            ]];

            $spouseName = null;
            $spouseNid = null;
            if ($social[$i % count($social)] === 'married') {
                $spouseName = ($isMaleHead
                    ? $firstNamesFemale[($i + 3) % count($firstNamesFemale)]
                    : $firstNamesMale[($i + 4) % count($firstNamesMale)]).' '.$ln;
                $spouseNid = (string) ($baseNid + 800000 + $i);
                $members[] = [
                    'name' => $spouseName,
                    'date_of_birth' => $this->dobFromYears(24 + ($i % 20)),
                    'relationship' => $isMaleHead ? 'زوجة' : 'زوج',
                    'gender' => $isMaleHead ? FamilyMember::GENDER_FEMALE : FamilyMember::GENDER_MALE,
                ];
            }

            for ($m = count($members) + 1; $m <= $membersCount; $m++) {
                $isBoy = (($i + $m) % 2) === 0;
                $age = ($i + $m) % 17;
                $members[] = [
                    'name' => ($isBoy
                        ? $firstNamesMale[($i + $m) % count($firstNamesMale)]
                        : $firstNamesFemale[($i + $m) % count($firstNamesFemale)]).' '.$ln,
                    'date_of_birth' => $age === 0 ? $this->dobNewborn() : $this->dobFromYears($age),
                    'relationship' => $isBoy ? 'ابن' : 'ابنة',
                    'gender' => $isBoy ? FamilyMember::GENDER_MALE : FamilyMember::GENDER_FEMALE,
                ];
            }

            $rows[] = [
                'national_id' => (string) ($baseNid + $i),
                'head_name' => $head,
                'head_gender' => $isMaleHead ? FamilyMember::GENDER_MALE : FamilyMember::GENDER_FEMALE,
                'phone' => '05'.substr((string) ($basePhone + $i), -8),
                'social_status' => $social[$i % count($social)],
                'financial_status' => $financial[$i % count($financial)],
                'file_status' => $fileStatuses[$i % count($fileStatuses)],
                'spouse_name' => $spouseName,
                'spouse_national_id' => $spouseNid,
                'original_governorate' => $govs[$i % count($govs)],
                'original_neighborhood' => $hoods[$i % count($hoods)].' ('.$prefix.')',
                'members' => $members,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{taiba: Camp, gaza: Camp, north: Camp}  $camps
     */
    private function printCredentials(array $camps): void
    {
        $lines = [
            '',
            '=== بيانات تجريبية (تَكافل) ===',
            'سوبر أدمن: username=superadmin | password=SuperPassword123!',
            'طيبة /admin: username=100100 | password=200200',
            'طيبة مسؤول إضافي: username=taiba2 | password=123456',
            'غزة /admin: username=200100 | password=200200',
            'شمال غزة /admin: username=300100 | password=200200  (اشتراك منتهٍ — العائلات محجوبة)',
            'مسارات المخيمات: /taiba  /gaza  /north-gaza',
            '',
            'دخول العائلات (طيبة): الرقم الوطني + الرقم التسلسلي (00 + id المستخدم):',
        ];

        foreach ($this->featuredLogins as $row) {
            $u = $row['user'];
            $lines[] = sprintf(
                '  %s | هوية %s | تسلسلي %s',
                $row['family']->head_name,
                $row['national_id'],
                User::defaultSerialFromId((int) $u->id)
            );
        }

        $lines[] = '================================';
        $lines[] = '';

        foreach ($lines as $line) {
            $this->command?->line($line);
        }
    }

    private function setTenant(?Camp $camp): void
    {
        if ($camp) {
            App::instance('current_camp_id', $camp->id);
            App::instance('current_camp', $camp);
        } else {
            App::forgetInstance('current_camp_id');
            App::forgetInstance('current_camp');
        }
    }

    private function dobFromYears(int $years): string
    {
        return Carbon::now()
            ->subYears(max(0, $years))
            ->subMonths(random_int(0, 11))
            ->subDays(random_int(0, 27))
            ->toDateString();
    }

    private function dobNewborn(): string
    {
        return Carbon::now()
            ->subMonths(random_int(0, 11))
            ->subDays(random_int(0, 27))
            ->toDateString();
    }
}
