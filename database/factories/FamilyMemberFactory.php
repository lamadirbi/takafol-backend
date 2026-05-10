<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyMember>
 */
class FamilyMemberFactory extends Factory
{
    protected $model = FamilyMember::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'name' => fake()->firstName(),
            'age' => fake()->numberBetween(1, 70),
            'relationship' => 'member',
            'gender' => fake()->randomElement([
                FamilyMember::GENDER_MALE,
                FamilyMember::GENDER_FEMALE,
            ]),
        ];
    }
}
