<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\TransactionEntry\Domain\FieldType;
use App\Modules\TransactionEntry\Domain\TransactionType;
use App\Modules\TransactionEntry\Domain\TransactionTypeMode;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of a transaction type version (TE-FR-02): a draft is edited freely, publishing freezes the
 * package, and retiring stops new entries without touching the old ones. A change to a published
 * version is a new draft with the next number.
 *
 * @phpstan-import-type FieldDefinition from \App\Modules\TransactionEntry\Domain\FieldSchema
 */
final class TransactionTypeVersions
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CompanyContext $company,
        private readonly AccountDirectory $accounts,
        private readonly DimensionSets $dimensions,
    ) {}

    /**
     * @param  list<FieldDefinition>  $fields
     *
     * @throws ValidationException
     */
    public function createDraft(string $typeId, TransactionTypeMode $mode, array $fields): TransactionTypeVersion
    {
        return DB::transaction(function () use ($typeId, $mode, $fields): TransactionTypeVersion {
            $type = TransactionType::query()->lockForUpdate()->findOrFail($typeId);

            $version = TransactionTypeVersion::create([
                'transaction_type_id' => $type->id,
                'version' => ((int) TransactionTypeVersion::query()->where('transaction_type_id', $type->id)->max('version')) + 1,
                'mode' => $mode,
                'status' => VersionStatus::Draft,
                'fields' => $this->checkedFields($mode, $fields),
            ])->refresh();

            $this->audit->record('transaction_type.version_drafted', "Version {$version->version} of {$type->key} drafted.", $version, after: ['version' => $version->version, 'mode' => $mode->value], companyId: $type->company_id);

            return $version;
        });
    }

    /**
     * @param  list<FieldDefinition>  $fields
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException
     */
    public function updateDraft(string $typeId, int $number, TransactionTypeMode $mode, array $fields): TransactionTypeVersion
    {
        return DB::transaction(function () use ($typeId, $number, $mode, $fields): TransactionTypeVersion {
            $version = $this->lockedVersion($typeId, $number);

            if ($version->status !== VersionStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $version->update(['mode' => $mode, 'fields' => $this->checkedFields($mode, $fields)]);

            return $version->refresh();
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException when a `structured` version has no fields
     */
    public function publish(string $typeId, int $number): TransactionTypeVersion
    {
        return DB::transaction(function () use ($typeId, $number): TransactionTypeVersion {
            $version = $this->lockedVersion($typeId, $number);

            if ($version->status !== VersionStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            if ($version->mode === TransactionTypeMode::Structured && ($version->fields ?? []) === []) {
                throw ValidationException::withMessages(['fields' => [__('entries.fields_required')]]);
            }

            $version->update(['status' => VersionStatus::Published]);

            $this->audit->record('transaction_type.version_published', "Version {$version->version} published.", $version, after: ['status' => 'published'], companyId: $version->company_id);

            return $version->refresh();
        });
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not published
     */
    public function retire(string $typeId, int $number): TransactionTypeVersion
    {
        return DB::transaction(function () use ($typeId, $number): TransactionTypeVersion {
            $version = $this->lockedVersion($typeId, $number);

            if ($version->status !== VersionStatus::Published) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $version->update(['status' => VersionStatus::Retired]);

            $this->audit->record('transaction_type.version_retired', "Version {$version->version} retired.", $version, before: ['status' => 'published'], after: ['status' => 'retired'], companyId: $version->company_id);

            return $version->refresh();
        });
    }

    private function lockedVersion(string $typeId, int $number): TransactionTypeVersion
    {
        TransactionType::query()->findOrFail($typeId);

        return TransactionTypeVersion::query()
            ->where('transaction_type_id', $typeId)
            ->where('version', $number)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Checks the definitions against what the company has and returns them in stored shape; a
     * `free_lines` version has no fields.
     *
     * @param  list<FieldDefinition>  $fields
     * @return list<FieldDefinition>|null
     *
     * @throws ValidationException
     */
    private function checkedFields(TransactionTypeMode $mode, array $fields): ?array
    {
        if ($mode === TransactionTypeMode::FreeLines) {
            if ($fields !== []) {
                throw ValidationException::withMessages(['fields' => [__('entries.fields_not_allowed')]]);
            }

            return null;
        }

        $companyId = $this->company->require();
        $names = [];
        $errors = [];
        $stored = [];

        foreach ($fields as $index => $field) {
            if (in_array($field['name'], $names, true)) {
                $errors["fields.{$index}.name"] = [__('entries.field_name_taken', ['name' => $field['name']])];
            }

            $names[] = $field['name'];
            $type = FieldType::from($field['type']);
            $definition = ['name' => $field['name'], 'type' => $type->value, 'label' => $field['label'], 'required' => $field['required'] ?? true];

            if ($type === FieldType::Account && isset($field['account_ids'])) {
                foreach ($field['account_ids'] as $accountId) {
                    if ($this->accounts->find($companyId, $accountId) === null) {
                        $errors["fields.{$index}.account_ids"] = [__('entries.field_account_unknown', ['id' => $accountId])];
                    }
                }

                $definition['account_ids'] = array_values(array_unique($field['account_ids']));
            }

            if ($type === FieldType::Dimension) {
                $key = $field['dimension_type'] ?? null;

                if ($key === null || ! in_array($key, $this->dimensions->activeTypeKeys($companyId), true)) {
                    $errors["fields.{$index}.dimension_type"] = [__('entries.field_dimension_type_unknown')];
                } else {
                    $definition['dimension_type'] = $key;
                }
            }

            $stored[] = $definition;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $stored;
    }
}
