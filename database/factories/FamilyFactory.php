<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => User::ROLE_FAMILY_HEAD]),
            'head_name' => fake()->name(),
            'national_id' => fake()->unique()->numerify('##########'),
            'phone' => fake()->phoneNumber(),
            'social_status' => 'married',
            'financial_status' => 'low',
            'total_members' => 1,
            'file_status' => 'active',
        ];
    }
}
