<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\BankImportMapping;
use App\Modules\Subledger\Domain\CashAccount;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the column mapping of the statement CSV of a cash account (SL-FR-13). The first save creates it;
 * later saves must carry the `lock_version` they read.
 */
final class ManageBankImportMapping
{
    public function __construct(private readonly Audit $audit) {}

    public function find(string $cashAccountId): ?BankImportMapping
    {
        CashAccount::query()->findOrFail($cashAccountId);

        return BankImportMapping::query()->where('cash_account_id', $cashAccountId)->first();
    }

    /**
     * @param  array<string, mixed>  $data  the fields of the mapping
     *
     * @throws ApiException VERSION_CONFLICT when the mapping exists and the version is missing or old
     * @throws ValidationException
     */
    public function save(string $cashAccountId, array $data, ?int $expectedVersion): BankImportMapping
    {
        $this->assertColumns($data);

        return DB::transaction(function () use ($cashAccountId, $data, $expectedVersion): BankImportMapping {
            $account = CashAccount::query()->lockForUpdate()->findOrFail($cashAccountId);
            $mapping = BankImportMapping::query()->where('cash_account_id', $cashAccountId)->lockForUpdate()->first();
            $fields = $this->complete($data);

            if ($mapping === null) {
                $mapping = BankImportMapping::create([...$fields, 'cash_account_id' => $cashAccountId])->refresh();

                $this->audit->record('bank_import_mapping.created', "Statement mapping of cash account {$account->name} created.", $mapping, after: $fields, companyId: $account->company_id);

                return $mapping;
            }

            if ($expectedVersion === null) {
                throw new ApiException(ErrorCode::VersionConflict);
            }

            $before = $mapping->only(BankImportMapping::FIELDS);
            $mapping->updateLocked($fields, $expectedVersion);

            $this->audit->record('bank_import_mapping.updated', "Statement mapping of cash account {$account->name} updated.", $mapping, before: $before, after: $fields, companyId: $account->company_id);

            return $mapping->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function complete(array $data): array
    {
        $fields = [];

        foreach (BankImportMapping::FIELDS as $field) {
            $fields[$field] = $data[$field] ?? null;
        }

        return $fields;
    }

    /**
     * The amount is one column, or a pair of columns for money in and money out.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertColumns(array $data): void
    {
        $single = ($data['amount_column'] ?? null) !== null;
        $in = ($data['money_in_column'] ?? null) !== null;
        $out = ($data['money_out_column'] ?? null) !== null;

        if ($single === ($in || $out) || $in !== $out) {
            throw ValidationException::withMessages(['amount_column' => [__('bank.mapping_amount_columns')]]);
        }
    }
}
