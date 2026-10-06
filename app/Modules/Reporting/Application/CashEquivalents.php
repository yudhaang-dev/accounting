<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The `cash_equivalents` role of Reporting: one or more asset accounts that the cash flow treats as
 * cash and cash equivalents (RP-FR-03, RP-FR-09). Versioned and append only like the roles of the
 * ledger; the accounts are checked against the chart through GeneralLedger (X-AC-03).
 */
final class CashEquivalents
{
    public const ROLE = 'cash_equivalents';

    public function __construct(
        private readonly Audit $audit,
        private readonly AccountDirectory $accounts,
    ) {}

    /**
     * The mapping in force on `$asOf` (the latest version when null), or null when never mapped.
     *
     * @param  string|null  $asOf  `YYYY-MM-DD`
     * @return array{account_ids: list<string>, version: int, effective_date: string}|null
     */
    public function current(string $companyId, ?string $asOf = null): ?array
    {
        $row = DB::table('rp_account_role_mappings')
            ->where('company_id', $companyId)
            ->where('account_role', self::ROLE)
            ->when($asOf !== null, fn ($query) => $query->where('effective_date', '<=', $asOf))
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var list<string> $ids */
        $ids = json_decode((string) $row->account_ids, true);

        return ['account_ids' => $ids, 'version' => (int) $row->version, 'effective_date' => substr((string) $row->effective_date, 0, 10)];
    }

    /**
     * Why each mapped account no longer qualifies (inactive, no longer a leaf), keyed by account ID;
     * an incomplete mapping shows up as a validation message instead of a wrong cash flow.
     *
     * @param  list<string>  $accountIds
     * @return array<string, list<string>>
     */
    public function problems(string $companyId, array $accountIds): array
    {
        $problems = [];

        foreach ($accountIds as $id) {
            $found = $this->accounts->roleTargetProblems($companyId, $id, [AccountClass::Asset]);

            if ($found !== []) {
                $problems[$id] = $found;
            }
        }

        return $problems;
    }

    /**
     * Maps the role to these accounts as a new version and audits the change.
     *
     * @param  list<string>  $accountIds  active asset leaves of the company
     * @param  string|null  $effectiveDate  `YYYY-MM-DD`; all dates for the first mapping, today afterwards
     * @return array{account_ids: list<string>, version: int, effective_date: string}
     *
     * @throws ValidationException on an account that cannot be a target
     * @throws ApiException VERSION_CONFLICT when the latest version is not `$expectedVersion` (0 when never mapped)
     */
    public function change(string $companyId, array $accountIds, int $expectedVersion, int $actorId, ?string $effectiveDate = null): array
    {
        $accountIds = array_values(array_unique($accountIds));
        $errors = [];

        foreach ($this->problems($companyId, $accountIds) as $id => $messages) {
            $index = array_search($id, $accountIds, true);
            $errors["account_ids.{$index}"] = $messages;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return DB::transaction(function () use ($companyId, $accountIds, $expectedVersion, $actorId, $effectiveDate): array {
                $before = $this->current($companyId);

                if (($before['version'] ?? 0) !== $expectedVersion) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                DB::table('rp_account_role_mappings')->insert([
                    'id' => (string) Str::ulid(),
                    'company_id' => $companyId,
                    'account_role' => self::ROLE,
                    'version' => $expectedVersion + 1,
                    'account_ids' => json_encode($accountIds),
                    'effective_date' => $effectiveDate ?? ($before === null ? '1970-01-01' : now()->toDateString()),
                    'created_by' => $actorId,
                    'created_at' => now(),
                ]);

                /** @var array{account_ids: list<string>, version: int, effective_date: string} $after */
                $after = $this->current($companyId);

                $this->audit->record(
                    'account_role.changed',
                    'Account role '.self::ROLE." mapped (version {$after['version']}).",
                    before: $before ?? [],
                    after: $after,
                    properties: ['account_role' => self::ROLE],
                    companyId: $companyId,
                );

                return $after;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(ErrorCode::VersionConflict);
        }
    }
}
