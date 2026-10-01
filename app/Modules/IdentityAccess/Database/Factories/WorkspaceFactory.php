<?php

namespace App\Modules\IdentityAccess\Database\Factories;

use App\Modules\IdentityAccess\Domain\Workspace;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\NewCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'company_id' => fn (): string => app(CompanyDirectory::class)
                ->create(new NewCompany(fake()->company(), 'IDR', 'Asia/Jakarta', 'id'))->id,
        ];
    }
}
