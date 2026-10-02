<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Contracts\ApprovalPolicies;
use App\Modules\TransactionEntry\Contracts\ApprovalPolicyData;
use App\Modules\TransactionEntry\Contracts\ApprovalRule;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Takes the company explicitly, so a workflow step can read it without more context. Every change is a
 * new row with its author; earlier versions stay as they were (P-04).
 */
final class EloquentApprovalPolicies implements ApprovalPolicies
{
    public function __construct(private readonly Audit $audit) {}

    public function current(string $companyId): ApprovalPolicyData
    {
        $row = DB::table('te_approval_policies')->where('company_id', $companyId)->orderByDesc('version')->first();

        return $row === null ? ApprovalPolicyData::none() : $this->data($row);
    }

    public function change(string $companyId, bool $separationOfDuties, array $rules, int $expectedVersion, int $actorId): ApprovalPolicyData
    {
        try {
            return DB::transaction(function () use ($companyId, $separationOfDuties, $rules, $expectedVersion, $actorId): ApprovalPolicyData {
                $before = $this->current($companyId);

                if ($before->version !== $expectedVersion) {
                    throw new ApiException(ErrorCode::VersionConflict);
                }

                DB::table('te_approval_policies')->insert([
                    'id' => (string) Str::ulid(),
                    'company_id' => $companyId,
                    'version' => $before->version + 1,
                    'separation_of_duties' => $separationOfDuties,
                    'rules' => json_encode(array_map($this->rule(...), $rules), JSON_THROW_ON_ERROR),
                    'created_by' => $actorId,
                    'created_at' => now(),
                ]);

                $after = $this->current($companyId);

                $this->audit->record(
                    'approval_policy.changed',
                    "Approval policy changed to version {$after->version}.",
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

    /** @return array<string, mixed> */
    private function snapshot(ApprovalPolicyData $policy): array
    {
        return ['version' => $policy->version, 'separation_of_duties' => $policy->separationOfDuties, 'rules' => array_map($this->rule(...), $policy->rules)];
    }

    /** @return array{transaction_type_key: string|null, source: string|null, threshold: string|null, stages: list<string>, auto_post: bool} */
    private function rule(ApprovalRule $rule): array
    {
        return [
            'transaction_type_key' => $rule->transactionTypeKey,
            'source' => $rule->source,
            'threshold' => $rule->threshold,
            'stages' => $rule->stages,
            'auto_post' => $rule->autoPost,
        ];
    }

    private function data(stdClass $row): ApprovalPolicyData
    {
        /** @var list<array{transaction_type_key: string|null, source: string|null, threshold: string|null, stages: list<string>, auto_post: bool}> $rules */
        $rules = json_decode((string) $row->rules, true, flags: JSON_THROW_ON_ERROR);

        return new ApprovalPolicyData(
            (int) $row->version,
            (bool) $row->separation_of_duties,
            array_map(fn (array $rule): ApprovalRule => new ApprovalRule($rule['transaction_type_key'], $rule['source'], $rule['threshold'], $rule['stages'], $rule['auto_post']), $rules),
        );
    }
}
