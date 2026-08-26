<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->call(ProductionSeeder::class);

            return;
        }

        $this->call([
            DemoDataSeeder::class,
        ]);
    }
}
