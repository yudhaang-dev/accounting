<?php

namespace App\Modules\MasterData\Database\Factories;

use App\Modules\MasterData\Domain\DimensionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Needs a company context and an explicit `dimension_type_id`.
 *
 * @extends Factory<DimensionValue>
 */
class DimensionValueFactory extends Factory
{
    protected $model = DimensionValue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('??-###')),
            'name' => fake()->words(2, true),
        ];
    }
}
