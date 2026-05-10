<?php

namespace Database\Factories;

use App\Models\PackageType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageType>
 */
class PackageTypeFactory extends Factory
{
    protected $model = PackageType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
