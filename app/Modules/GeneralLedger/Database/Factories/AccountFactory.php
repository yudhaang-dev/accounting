<?php

namespace App\Modules\GeneralLedger\Database\Factories;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Domain\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Needs a company context (or an explicit `company_id` with the scope bypassed), like any company-scoped model.
 *
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->words(3, true),
            'class' => AccountClass::Asset,
            'normal_balance' => 'debit',
        ];
    }
}
