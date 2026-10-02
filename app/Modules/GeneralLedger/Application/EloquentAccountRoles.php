<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoleMapping;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Takes the company explicitly, so posting can read it without a request context. Every change is a
 * new row with its author; earlier versions stay as they were (P-04).
 */
final class EloquentAccountRoles implements AccountRoles
{
    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly Audit $audit,
    ) {}

    public function current(string $companyId, AccountRole $role, ?string $asOf = null): ?AccountRoleMapping
    {
        $row = DB::table('gl_account_role_mappings')
            ->where('company_id', $companyId)
            ->where('account_role', $role->value)
            ->when($asOf !== null, fn ($query) => $query->whereDate('effective_date', '<=', $asOf))
            ->orderByDesc('version')
            ->first();

        return $row === null ? null : $this->mapping($row);
    }

    public function all(string $companyId): array
    {
        $mappings = [];

        foreach (AccountRole::cases() as $role) {
            if (($mapping = $this->current($companyId, $role)) !== null) {
                $mappings[$role->value] = $mapping;
            }
        }

        return $mappings;
    }

    public function require(string $companyId, AccountRole $role, ?string $asOf = null): AccountRoleMapping
    {
        $mapping = $this->current($companyId, $role, $asOf);

        if ($mapping === null) {
            throw new ApiException(ErrorCode::MappingRequired, errors: [
                $role->value => [__('ledger.role_unmapped', ['key' => $role->value])],
            ]);
        }

        $problems = $this->accounts->roleTargetProblems($companyId, $mapping->accountId, $role->allowedClasses());

        if ($problems !== []) {
            throw new ApiException(ErrorCode::MappingRequired, errors: [$role->value => $problems]);
        }

        return $mapping;
    }

    public function missing(string $companyId, array $roles): array
    {
        return array_values(array_filter($roles, fn (AccountRole $role): bool => $this->current($companyId, $role) === null));
    }

    public function change(string $companyId, AccountRole $role, string $accountId, int $expectedVersion, int $actorId, ?string $effectiveDate = null): AccountRoleMapping
    {
        $problems = $this->accounts->roleTargetProblems($companyId, $accountId, $role->allowedClasses());

        if ($problems !== []) {
            throw ValidationException::withMessages(['account_id' => $problems]);
        }

        try {
            return DB::transaction(function () use ($companyId, $role, $accountId, $expectedVersion, $actorId, $effectiveDate): AccountRoleMapping {
                $before = $this->current($companyId, $role);

                if (($before->version ?? 0) !== $expectedVersion) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                DB::table('gl_account_role_mappings')->insert([
                    'id' => (string) Str::ulid(),
                    'company_id' => $companyId,
                    'account_role' => $role->value,
                    'version' => $expectedVersion + 1,
                    'account_id' => $accountId,
                    'effective_date' => $effectiveDate ?? now()->toDateString(),
                    'created_by' => $actorId,
                    'created_at' => now(),
                ]);

                /** @var AccountRoleMapping $after */
                $after = $this->current($companyId, $role);

                $this->audit->record(
                    'account_role.changed',
                    "Account role {$role->value} mapped (version {$after->version}).",
                    before: $before === null ? [] : ['account_id' => $before->accountId, 'version' => $before->version],
                    after: ['account_id' => $after->accountId, 'version' => $after->version, 'effective_date' => $after->effectiveDate],
                    properties: ['account_role' => $role->value],
                    companyId: $companyId,
                );

                return $after;
            });
        } catch (UniqueConstraintViolationException) {
            // Another editor added the same version first.
            throw new ApiException(ErrorCode::VersionConflict);
        }
    }

    private function mapping(stdClass $row): AccountRoleMapping
    {
        return new AccountRoleMapping(AccountRole::from($row->account_role), $row->account_id, (int) $row->version, substr((string) $row->effective_date, 0, 10));
    }
}
