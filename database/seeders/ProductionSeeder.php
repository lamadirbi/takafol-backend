<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * إنتاج: حساب الإدارة العليا فقط — بدون مخيمات أو عائلات تجريبية.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $username = (string) env('SUPER_ADMIN_USERNAME', 'superadmin');
        $nationalId = (string) env('SUPER_ADMIN_NATIONAL_ID', '0000000000');
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        if ($password === '') {
            throw new \RuntimeException('SUPER_ADMIN_PASSWORD is required in production.');
        }

        $exists = User::withoutGlobalScopes()
            ->where(function ($query) use ($username, $nationalId) {
                $query->where('username', $username)
                    ->orWhere('national_id', $nationalId);
            })
            ->exists();

        if ($exists) {
            $this->command?->info('حساب الإدارة العليا موجود مسبقاً — لم يُمس.');

            return;
        }

        User::withoutGlobalScopes()->create([
            'name' => (string) env('SUPER_ADMIN_NAME', 'المدير العام'),
            'username' => $username,
            'national_id' => $nationalId,
            'email' => env('SUPER_ADMIN_EMAIL') ?: null,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'is_super' => true,
            'camp_id' => null,
        ]);

        $this->command?->info('تم إنشاء حساب الإدارة العليا: '.$username);
    }
}
