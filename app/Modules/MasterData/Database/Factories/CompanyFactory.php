<?php

namespace App\Modules\MasterData\Database\Factories;

use App\Modules\MasterData\Domain\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'functional_currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'default_locale' => 'id',
        ];
    }
}
