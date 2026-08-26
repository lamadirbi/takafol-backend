<?php

namespace Database\Seeders;

use App\Models\CampRegistrationRequest;
use Illuminate\Database\Seeder;

class CampRegistrationRequestSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'applicant_name' => 'أبو محمد البرجي',
                'camp_name' => 'مخيم البريج',
                'whatsapp_phone' => '0597001001',
                'payment_notification_whatsapp' => '0597001001',
                'message' => 'نحتاج منصة لتسجيل العائلات وتنظيم توزيع الطرود في مخيم البريج. اللجنة جاهزة للبدء هذا الأسبوع.',
                'status' => CampRegistrationRequest::STATUS_PENDING,
                'admin_note' => null,
            ],
            [
                'applicant_name' => 'لجنة مخيم المغازي',
                'camp_name' => 'مخيم المغازي',
                'whatsapp_phone' => '0597001002',
                'payment_notification_whatsapp' => '0597001002',
                'message' => 'طلب انضمام لإدارة سجل العائلات والإعلانات. عدد العائلات التقريبي 180.',
                'status' => CampRegistrationRequest::STATUS_PENDING,
                'admin_note' => null,
            ],
            [
                'applicant_name' => 'منى أبو سليم',
                'camp_name' => 'مخيم النصيرات',
                'whatsapp_phone' => '0592533678',
                'payment_notification_whatsapp' => '0592533678',
                'message' => 'لدينا لجنة نسائية تشرف على التوزيع ونريد حساب إدارة وعائلات.',
                'status' => CampRegistrationRequest::STATUS_PENDING,
                'admin_note' => null,
            ],
            [
                'applicant_name' => 'يوسف العكلوك',
                'camp_name' => 'مخيم جباليا البلد',
                'whatsapp_phone' => '0598112233',
                'payment_notification_whatsapp' => '0598112234',
                'message' => 'نحتاج فلترة للعائلات ذات حديثي الولادة وتوزيع حليب الأطفال.',
                'status' => CampRegistrationRequest::STATUS_PENDING,
                'admin_note' => null,
            ],
            [
                'applicant_name' => 'سعيد أبو حصيرة',
                'camp_name' => 'مخيم بيت حانون',
                'whatsapp_phone' => '0599001122',
                'payment_notification_whatsapp' => null,
                'message' => 'اللجنة جديدة ونريد تجربة المنصة قبل الاشتراك الشهري.',
                'status' => CampRegistrationRequest::STATUS_PENDING,
                'admin_note' => null,
            ],
            [
                'applicant_name' => 'أحمد أبو عودة',
                'camp_name' => 'مخيم دير البلح',
                'whatsapp_phone' => '0597001003',
                'payment_notification_whatsapp' => '0597001003',
                'message' => 'تم الاتفاق مسبقاً على بدء العمل عبر المنصة.',
                'status' => CampRegistrationRequest::STATUS_APPROVED,
                'admin_note' => 'تم التحقق من اللجنة. أنشئ المخيم يدوياً وأُرسل رابط الدخول عبر واتساب.',
            ],
            [
                'applicant_name' => 'فاطمة الزهار',
                'camp_name' => 'مخيم خان يونس البلد',
                'whatsapp_phone' => '0596223344',
                'payment_notification_whatsapp' => '0596223344',
                'message' => 'نظمنا التوزيع ورقياً ونريد الانتقال للمنصة.',
                'status' => CampRegistrationRequest::STATUS_APPROVED,
                'admin_note' => 'لجنة معروفة. المخيم سيُنشأ باسم khanyunis.',
            ],
            [
                'applicant_name' => 'خالد النجار',
                'camp_name' => 'مخيم غير مكتمل البيانات',
                'whatsapp_phone' => '0000',
                'payment_notification_whatsapp' => null,
                'message' => 'بيانات ناقصة للتواصل.',
                'status' => CampRegistrationRequest::STATUS_REJECTED,
                'admin_note' => 'رقم الواتساب غير صالح أو لا يمكن التواصل عليه',
            ],
            [
                'applicant_name' => 'طلب مكرر',
                'camp_name' => 'مخيم طيبة التربوي',
                'whatsapp_phone' => '0591112233',
                'payment_notification_whatsapp' => '0591112233',
                'message' => 'نريد فتح مخيم طيبة على المنصة.',
                'status' => CampRegistrationRequest::STATUS_REJECTED,
                'admin_note' => 'اسم المخيم غير واضح أو مكرر لمخيم موجود',
            ],
            [
                'applicant_name' => 'س',
                'camp_name' => 'مخيم',
                'whatsapp_phone' => '059123',
                'payment_notification_whatsapp' => null,
                'message' => null,
                'status' => CampRegistrationRequest::STATUS_REJECTED,
                'admin_note' => 'بيانات اللجنة ناقصة ولا تكفي لإنشاء الحساب',
            ],
        ];

        foreach ($rows as $row) {
            CampRegistrationRequest::query()->updateOrCreate(
                [
                    'applicant_name' => $row['applicant_name'],
                    'camp_name' => $row['camp_name'],
                ],
                $row
            );
        }

        $this->command?->info('طلبات تسجيل المخيمات: '.count($rows).' سجلات تجريبية.');
    }
}
