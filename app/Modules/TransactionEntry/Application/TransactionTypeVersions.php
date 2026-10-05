<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;
use App\Modules\TransactionEntry\Domain\FieldType;
use App\Modules\TransactionEntry\Domain\PostingRules;
use App\Modules\TransactionEntry\Domain\SubledgerRoute;
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
 * @phpstan-import-type RuleLine from PostingRules
 */
final class TransactionTypeVersions
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CompanyContext $company,
        private readonly AccountDirectory $accounts,
        private readonly DimensionSets $dimensions,
        private readonly Currencies $currencies,
    ) {}

    /**
     * @param  list<FieldDefinition>  $fields
     * @param  list<array<string, mixed>>  $rules
     * @param  array{allowed_currencies?: list<string>|null, rate_type_key?: string|null, allow_rate_input?: bool}  $currencyPolicy
     * @param  array<string, mixed>|null  $route  the subledger route (TE-FR-10)
     *
     * @throws ValidationException
     */
    public function createDraft(string $typeId, TransactionTypeMode $mode, array $fields, array $rules = [], array $currencyPolicy = [], ?array $route = null): TransactionTypeVersion
    {
        return DB::transaction(function () use ($typeId, $mode, $fields, $rules, $currencyPolicy, $route): TransactionTypeVersion {
            $type = TransactionType::query()->lockForUpdate()->findOrFail($typeId);

            $checked = $this->checkedFields($mode, $fields);
            $checkedRules = $this->checkedRules($mode, $checked, $rules);

            $version = TransactionTypeVersion::create([
                'transaction_type_id' => $type->id,
                'version' => ((int) TransactionTypeVersion::query()->where('transaction_type_id', $type->id)->max('version')) + 1,
                'mode' => $mode,
                'status' => VersionStatus::Draft,
                'fields' => $checked,
                'posting_rules' => $checkedRules,
                'subledger_route' => $this->checkedRoute($mode, $checked, $checkedRules, $route),
                ...$this->checkedCurrencyPolicy($currencyPolicy),
            ])->refresh();

            $this->audit->record('transaction_type.version_drafted', "Version {$version->version} of {$type->key} drafted.", $version, after: ['version' => $version->version, 'mode' => $mode->value], companyId: $type->company_id);

            return $version;
        });
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @param  list<array<string, mixed>>  $rules
     * @param  array{allowed_currencies?: list<string>|null, rate_type_key?: string|null, allow_rate_input?: bool}  $currencyPolicy
     * @param  array<string, mixed>|null  $route  the subledger route (TE-FR-10)
     *
     * @throws ApiException INVALID_STATE_TRANSITION when the version is not a draft
     * @throws ValidationException
     */
    public function updateDraft(string $typeId, int $number, TransactionTypeMode $mode, array $fields, array $rules = [], array $currencyPolicy = [], ?array $route = null): TransactionTypeVersion
    {
        return DB::transaction(function () use ($typeId, $number, $mode, $fields, $rules, $currencyPolicy, $route): TransactionTypeVersion {
            $version = $this->lockedVersion($typeId, $number);

            if ($version->status !== VersionStatus::Draft) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $checked = $this->checkedFields($mode, $fields);

            $checkedRules = $this->checkedRules($mode, $checked, $rules);

            $version->update([
                'mode' => $mode,
                'fields' => $checked,
                'posting_rules' => $checkedRules,
                'subledger_route' => $this->checkedRoute($mode, $checked, $checkedRules, $route),
                ...$this->checkedCurrencyPolicy($currencyPolicy),
            ]);

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

            if ($version->mode === TransactionTypeMode::Structured) {
                $this->assertPublishable($version);
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

    /**
     * The currencies a version accepts must be active in the company (TE-FR-06). Without a policy a
     * version accepts any active currency and takes no rate from the entry.
     *
     * @param  array{allowed_currencies?: list<string>|null, rate_type_key?: string|null, allow_rate_input?: bool}  $policy
     * @return array{allowed_currencies: list<string>|null, rate_type_key: string|null, allow_rate_input: bool}
     *
     * @throws ValidationException
     */
    private function checkedCurrencyPolicy(array $policy): array
    {
        $companyId = $this->company->require();
        $currencies = $policy['allowed_currencies'] ?? null;
        $errors = [];

        foreach ($currencies ?? [] as $index => $code) {
            if (! $this->currencies->isActive($companyId, $code)) {
                $errors["allowed_currencies.{$index}"] = [__('entries.currency_inactive', ['currency' => $code])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'allowed_currencies' => $currencies,
            'rate_type_key' => $policy['rate_type_key'] ?? null,
            'allow_rate_input' => $policy['allow_rate_input'] ?? false,
        ];
    }

    /**
     * A structured version needs fields and rules, and a field several rules use must be declared as
     * shared by each of them (TE-FR-05, TE-AC-06).
     *
     * @throws ValidationException
     */
    private function assertPublishable(TransactionTypeVersion $version): void
    {
        if (($version->fields ?? []) === []) {
            throw ValidationException::withMessages(['fields' => [__('entries.fields_required')]]);
        }

        $rules = $version->posting_rules ?? [];

        if ($rules === []) {
            throw ValidationException::withMessages(['posting_rules' => [__('entries.rules_required')]]);
        }

        $errors = [];

        foreach (PostingRules::undeclaredReuse($rules) as $index) {
            $errors["posting_rules.{$index}.reuses_field"] = [__('entries.rule_reuse_undeclared')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Checks the rules against the fields and accounts of the company and returns them in stored shape;
     * a `free_lines` version has none.
     *
     * @param  list<FieldDefinition>|null  $fields  the checked fields of the version
     * @param  list<array<string, mixed>>  $rules
     * @return list<RuleLine>|null
     *
     * @throws ValidationException
     */
    private function checkedRules(TransactionTypeMode $mode, ?array $fields, array $rules): ?array
    {
        if ($mode === TransactionTypeMode::FreeLines) {
            if ($rules !== []) {
                throw ValidationException::withMessages(['posting_rules' => [__('entries.rules_not_allowed')]]);
            }

            return null;
        }

        $companyId = $this->company->require();
        $typeOf = [];

        foreach ($fields ?? [] as $field) {
            $typeOf[$field['name']] = $field['type'];
        }

        $errors = [];
        $stored = [];
        $fxLines = 0;

        foreach ($rules as $index => $rule) {
            $type = $rule['type'] ?? null;
            $applyDimensions = (bool) ($rule['apply_dimensions'] ?? false);
            $reuses = (bool) ($rule['reuses_field'] ?? false);

            if ($type === PostingRules::FX_DIFFERENCE) {
                if (++$fxLines > 1) {
                    $errors["posting_rules.{$index}.type"] = [__('entries.rule_fx_once')];
                }

                $stored[] = ['type' => $type, 'apply_dimensions' => $applyDimensions, 'reuses_field' => false];

                continue;
            }

            $amountField = $rule['amount_field'] ?? null;
            $account = $rule['account'] ?? null;
            $side = $rule['side'] ?? null;

            if (! in_array($side, ['debit', 'credit'], true)) {
                $errors["posting_rules.{$index}.side"] = [__('entries.rule_side_required')];
            }

            if (! is_string($amountField) || ($typeOf[$amountField] ?? null) !== FieldType::Amount->value) {
                $errors["posting_rules.{$index}.amount_field"] = [__('entries.rule_amount_field_unknown')];
            }

            $source = is_array($account) ? ($account['source'] ?? null) : null;
            $storedAccount = null;

            if ($source === PostingRules::FIXED_ACCOUNT && is_string($account['account_id'] ?? null)) {
                if ($this->accounts->find($companyId, $account['account_id']) === null) {
                    $errors["posting_rules.{$index}.account.account_id"] = [__('entries.field_account_unknown', ['id' => $account['account_id']])];
                }

                $storedAccount = ['source' => $source, 'account_id' => $account['account_id']];
            } elseif ($source === PostingRules::ACCOUNT_FIELD && is_string($account['field'] ?? null)) {
                if (($typeOf[$account['field']] ?? null) !== FieldType::Account->value) {
                    $errors["posting_rules.{$index}.account.field"] = [__('entries.rule_account_field_unknown')];
                }

                $storedAccount = ['source' => $source, 'field' => $account['field']];
            } elseif ($source === PostingRules::CONTROL_ACCOUNT && is_string($account['party_field'] ?? null)) {
                if (($typeOf[$account['party_field']] ?? null) !== FieldType::Party->value) {
                    $errors["posting_rules.{$index}.account.party_field"] = [__('entries.rule_party_field_unknown')];
                }

                $storedAccount = ['source' => $source, 'party_field' => $account['party_field']];
            } elseif ($source === PostingRules::CASH_ACCOUNT && is_string($account['field'] ?? null)) {
                if (($typeOf[$account['field']] ?? null) !== FieldType::CashAccount->value) {
                    $errors["posting_rules.{$index}.account.field"] = [__('entries.rule_cash_field_unknown')];
                }

                $storedAccount = ['source' => $source, 'field' => $account['field']];
            } else {
                $errors["posting_rules.{$index}.account"] = [__('entries.rule_account_required')];
            }

            $stored[] = [
                'type' => PostingRules::AMOUNT,
                'side' => (string) $side,
                'amount_field' => (string) $amountField,
                'account' => $storedAccount ?? ['source' => ''],
                'apply_dimensions' => $applyDimensions,
                'reuses_field' => $reuses,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $stored;
    }

    /**
     * The route that sends an entry to the subledger (TE-FR-10), checked against the fields and the rules
     * of the version, which must agree with it: the rules post to the control account of the party the route
     * names, on the side its kind of document needs, and to the cash account only when the document moves cash.
     * A rule that posts to a control or cash account makes no sense without a route.
     *
     * @param  list<FieldDefinition>|null  $fields
     * @param  list<RuleLine>|null  $rules
     * @param  array<string, mixed>|null  $route
     * @return array{kind: string, control_role: string, party_field: string, document_number_field: string|null, due_date_field: string|null, cash_account_field: string|null, allocations_field: string|null}|null
     *
     * @throws ValidationException
     */
    private function checkedRoute(TransactionTypeMode $mode, ?array $fields, ?array $rules, ?array $route): ?array
    {
        $errors = [];

        if ($mode === TransactionTypeMode::FreeLines && $route !== null) {
            throw ValidationException::withMessages(['subledger_route' => [__('entries.route_not_allowed')]]);
        }

        if ($route === null) {
            foreach ($rules ?? [] as $index => $rule) {
                if (in_array($rule['account']['source'] ?? null, [PostingRules::CONTROL_ACCOUNT, PostingRules::CASH_ACCOUNT], true)) {
                    $errors["posting_rules.{$index}.account"] = [__('entries.rule_route_required')];
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return null;
        }

        $stored = SubledgerRoute::normalize($route);
        $kind = DocumentKind::tryFrom($stored['kind']);
        $role = ControlRole::tryFrom($stored['control_role']);
        $typeOf = [];

        foreach ($fields ?? [] as $field) {
            $typeOf[$field['name']] = $field['type'];
        }

        if ($kind === null) {
            $errors['subledger_route.kind'] = [__('entries.route_kind_unknown')];
        }

        if ($role === null) {
            $errors['subledger_route.control_role'] = [__('entries.route_role_unknown')];
        }

        if ($stored['party_field'] === '') {
            $errors['subledger_route.party_field'] = [__('entries.route_party_field_required')];
        }

        foreach (SubledgerRoute::fields($stored) as $used) {
            if (($typeOf[$used['name']] ?? null) !== $used['type']->value) {
                $errors["subledger_route.{$used['key']}"] = [__('entries.route_field_type', ['field' => $used['name'], 'type' => $used['type']->value])];
            }
        }

        if ($kind !== null) {
            if ($kind->movesCash() && $stored['cash_account_field'] === null) {
                $errors['subledger_route.cash_account_field'] = [__('entries.route_cash_field_required', ['kind' => $kind->value])];
            } elseif (! $kind->movesCash() && $stored['cash_account_field'] !== null) {
                $errors['subledger_route.cash_account_field'] = [__('entries.route_cash_field_not_allowed', ['kind' => $kind->value])];
            }
        }

        if ($kind !== null) {
            if (! $kind->allocates() && $stored['allocations_field'] !== null) {
                $errors['subledger_route.allocations_field'] = [__('entries.route_allocations_not_allowed', ['kind' => $kind->value])];
            } elseif ($kind === DocumentKind::WriteOff && $stored['allocations_field'] === null) {
                $errors['subledger_route.allocations_field'] = [__('entries.route_allocations_required', ['kind' => $kind->value])];
            }
        }

        if ($errors === []) {
            $errors = $this->ruleErrors($rules ?? [], $stored, $kind->controlSide($role), $kind->movesCash());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $stored;
    }

    /**
     * @param  list<RuleLine>  $rules
     * @param  array{kind: string, control_role: string, party_field: string, document_number_field: string|null, due_date_field: string|null, cash_account_field: string|null, allocations_field: string|null}  $route
     * @param  'debit'|'credit'  $controlSide
     * @return array<string, list<string>>
     */
    private function ruleErrors(array $rules, array $route, string $controlSide, bool $movesCash): array
    {
        $cashSide = $controlSide === 'debit' ? 'credit' : 'debit';
        $errors = [];
        $control = 0;
        $cash = 0;

        foreach ($rules as $index => $rule) {
            $source = $rule['account']['source'] ?? null;

            if ($source === PostingRules::CONTROL_ACCOUNT) {
                $control++;

                if (($rule['account']['party_field'] ?? null) !== $route['party_field']) {
                    $errors["posting_rules.{$index}.account.party_field"] = [__('entries.rule_party_field_route', ['field' => $route['party_field']])];
                }

                if (($rule['side'] ?? null) !== $controlSide) {
                    $errors["posting_rules.{$index}.side"] = [__('entries.rule_control_side', ['kind' => $route['kind'], 'side' => $controlSide])];
                }
            } elseif ($source === PostingRules::CASH_ACCOUNT) {
                $cash++;

                if (! $movesCash) {
                    $errors["posting_rules.{$index}.account"] = [__('entries.rule_cash_not_allowed', ['kind' => $route['kind']])];

                    continue;
                }

                if (($rule['account']['field'] ?? null) !== $route['cash_account_field']) {
                    $errors["posting_rules.{$index}.account.field"] = [__('entries.rule_cash_field_route', ['field' => (string) $route['cash_account_field']])];
                }

                if (($rule['side'] ?? null) !== $cashSide) {
                    $errors["posting_rules.{$index}.side"] = [__('entries.rule_cash_side', ['kind' => $route['kind'], 'side' => $cashSide])];
                }
            }
        }

        if ($control === 0) {
            $errors['subledger_route'][] = __('entries.route_control_rule_required');
        }

        if ($movesCash && $cash === 0) {
            $errors['subledger_route'][] = __('entries.route_cash_rule_required', ['kind' => $route['kind']]);
        }

        return $errors;
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
