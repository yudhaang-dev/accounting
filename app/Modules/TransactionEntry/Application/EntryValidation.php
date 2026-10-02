<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\FieldType;
use App\Modules\TransactionEntry\Domain\FieldValues;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Modules\TransactionEntry\Domain\VersionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * What the entry module checks of an entry when it is submitted and again when it is posted (TE-FR-08):
 * that its version still takes entries, that the values fit the field schema and the scale of the
 * currency, that the accounts they name are in the company, and that the currency is still one the
 * version and the company accept. Period, account rules, dimensions, rates and balance belong to the
 * ledger, which checks them next. All the problems found here come back together, per field.
 */
final class EntryValidation
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly AccountDirectory $accounts,
        private readonly EntryRates $rates,
    ) {}

    /**
     * @throws ApiException TRANSACTION_TYPE_VERSION_RETIRED
     * @throws ValidationException
     */
    public function assertValid(Entry $entry): void
    {
        $version = $entry->typeVersion();

        if ($version->status === VersionStatus::Retired) {
            throw new ApiException(ErrorCode::TransactionTypeVersionRetired);
        }

        $errors = [];

        if ($entry->field_values !== null) {
            $fields = $version->fields ?? [];
            $values = $entry->field_values;
            $errors = FieldValues::errors($fields, $values);
            $scale = $this->rates->scaleOf($entry->currency);
            $companyId = $this->company->require();

            foreach ($fields as $field) {
                $value = $values[$field['name']] ?? null;
                $key = "values.{$field['name']}";

                if (! is_string($value) || isset($errors[$key])) {
                    continue;
                }

                if ($field['type'] === FieldType::Amount->value && $this->decimals($value) > $scale) {
                    $errors[$key] = [__('entries.value_scale', ['field' => $field['label'], 'scale' => $scale])];
                } elseif ($field['type'] === FieldType::Account->value && $this->accounts->find($companyId, $value) === null) {
                    $errors[$key] = [__('entries.field_account_unknown', ['id' => $value])];
                }
            }

            $errors += $this->currencyErrors($version, $entry->currency, $entry->exchange_rate, 'currency', 'exchange_rate');
        } else {
            foreach ($entry->lines as $index => $line) {
                $errors += $this->currencyErrors($version, $line['currency'] ?? null, $line['exchange_rate'] ?? null, "lines.{$index}.currency", "lines.{$index}.exchange_rate");
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function currencyErrors(TransactionTypeVersion $version, ?string $currency, ?string $rate, string $currencyKey, string $rateKey): array
    {
        try {
            $this->rates->assertAllowed($version, $currency, $rate, $currencyKey, $rateKey);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> */
            return $e->errors();
        }

        return [];
    }

    private function decimals(string $amount): int
    {
        $position = strpos($amount, '.');

        return $position === false ? 0 : strlen(rtrim(substr($amount, $position + 1), '0'));
    }
}
