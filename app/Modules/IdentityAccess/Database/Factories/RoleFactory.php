<?php

namespace App\Modules\IdentityAccess\Database\Factories;

use App\Modules\IdentityAccess\Domain\Role;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\NewCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'guard_name' => 'web',
            'company_id' => fn (): string => app(CompanyDirectory::class)
                ->create(new NewCompany(fake()->company(), 'IDR', 'Asia/Jakarta', 'id'))->id,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
