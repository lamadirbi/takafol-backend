<?php

namespace Database\Factories;

use App\Models\Distribution;
use App\Models\Family;
use App\Models\PackageType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Distribution>
 */
class DistributionFactory extends Factory
{
    protected $model = Distribution::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'package_type_id' => PackageType::factory(),
            'status' => Distribution::STATUS_PENDING,
            'delivered_at' => null,
            'administered_by' => null,
        ];
    }
}
