<?php

namespace App\Modules\IdentityAccess\Database\Factories;

use App\Models\User;
use App\Modules\IdentityAccess\Domain\Membership;
use App\Modules\IdentityAccess\Domain\MembershipStatus;
use App\Modules\IdentityAccess\Domain\Workspace;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(['status' => MembershipStatus::Revoked]);
    }

    public function expiredAt(DateTimeInterface $at): static
    {
        return $this->state(['expires_at' => $at]);
    }
}
