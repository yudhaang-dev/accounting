<?php

namespace App\Modules\MasterData\Database\Factories;

use App\Modules\MasterData\Domain\DimensionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Needs a company context (or an explicit `company_id` with the scope bypassed), like any company-scoped model.
 *
 * @extends Factory<DimensionType>
 */
class DimensionTypeFactory extends Factory
{
    protected $model = DimensionType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'dim_'.fake()->unique()->numerify('####'),
            'label' => fake()->words(2, true),
        ];
    }
}
