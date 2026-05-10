<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminTestSeeder extends Seeder
{
    public function run()
    {
        User::updateOrCreate(
            ['username' => 'superadmin'],
            [
                'name' => 'المدير العام',
                'national_id' => '000000001',
                'password' => Hash::make('123456'),
                'role' => 'admin',
                'is_super' => true
            ]
        );

        User::updateOrCreate(
            ['username' => 'campadmin'],
            [
                'name' => 'مدير مخيم طيبة',
                'national_id' => '000000002',
                'password' => Hash::make('123456'),
                'role' => 'admin',
                'is_super' => false,
                'camp_id' => 1 // Assuming taiba has id=1
            ]
        );
    }
}
