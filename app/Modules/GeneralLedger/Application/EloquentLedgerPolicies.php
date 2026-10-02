<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\LedgerPolicyData;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Takes the company explicitly, so posting can read it without a request context. Every change is a
 * new row with its author; earlier versions stay as they were (P-04).
 */
final class EloquentLedgerPolicies implements LedgerPolicies
{
    public function __construct(private readonly Audit $audit) {}

    public function current(string $companyId): LedgerPolicyData
    {
        $row = DB::table('gl_ledger_policies')->where('company_id', $companyId)->orderByDesc('version')->first();

        return $row === null ? LedgerPolicyData::none() : $this->data($row);
    }

    public function change(string $companyId, array $softClosedTransactionTypeKeys, ?string $softClosedPermission, int $expectedVersion, int $actorId): LedgerPolicyData
    {
        try {
            return DB::transaction(function () use ($companyId, $softClosedTransactionTypeKeys, $softClosedPermission, $expectedVersion, $actorId): LedgerPolicyData {
                $before = $this->current($companyId);

                if ($before->version !== $expectedVersion) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                $keys = array_values(array_unique($softClosedTransactionTypeKeys));
                sort($keys);

                DB::table('gl_ledger_policies')->insert([
                    'id' => (string) Str::ulid(),
                    'company_id' => $companyId,
                    'version' => $before->version + 1,
                    'soft_closed_transaction_type_keys' => json_encode($keys, JSON_THROW_ON_ERROR),
                    'soft_closed_permission' => $softClosedPermission,
                    'created_by' => $actorId,
                    'created_at' => now(),
                ]);

                $after = $this->current($companyId);

                $this->audit->record(
                    'ledger_policy.changed',
                    "Ledger policy changed to version {$after->version}.",
                    before: $this->snapshot($before),
                    after: $this->snapshot($after),
                    companyId: $companyId,
                );

                return $after;
            });
        } catch (UniqueConstraintViolationException) {
            // Another editor added the same version first.
            throw new ApiException(ErrorCode::VersionConflict);
        }
    }

    /** @return array{version: int, soft_closed_transaction_type_keys: list<string>, soft_closed_permission: string|null} */
    private function snapshot(LedgerPolicyData $policy): array
    {
        return [
            'version' => $policy->version,
            'soft_closed_transaction_type_keys' => $policy->softClosedTransactionTypeKeys,
            'soft_closed_permission' => $policy->softClosedPermission,
        ];
    }

    private function data(stdClass $row): LedgerPolicyData
    {
        /** @var list<string> $keys */
        $keys = json_decode((string) $row->soft_closed_transaction_type_keys, true, flags: JSON_THROW_ON_ERROR);

        return new LedgerPolicyData((int) $row->version, $keys, $row->soft_closed_permission);
    }
}
