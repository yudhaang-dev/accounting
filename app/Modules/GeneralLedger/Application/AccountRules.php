<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\CurrencyRestriction;
use App\Modules\GeneralLedger\Contracts\DimensionRequirement;
use App\Modules\GeneralLedger\Domain\Account;
use App\Modules\GeneralLedger\Domain\JournalLine;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Company\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Invariants of the chart of accounts (GL-FR-01) shared by the account actions. All lookups go
 * through the company scope, so an account of another company simply does not exist here (P-05).
 */
final class AccountRules
{
    /** Attributes of GL-FR-02 with the values of an account that restricts nothing. */
    public const POSTING_DEFAULTS = [
        'allowed_sources' => ['entry', 'subledger', 'system'],
        'dimension_requirements' => [],
        'currency_restriction' => 'any',
        'restricted_currency' => null,
        'is_revaluable' => false,
    ];

    public function __construct(
        private readonly CompanyContext $company,
        private readonly DimensionSets $dimensionSets,
        private readonly Currencies $currencies,
        private readonly CompanyDirectory $companies,
    ) {}

    /**
     * @throws ValidationException
     */
    public function assertCodeAvailable(string $code, ?string $exceptAccountId = null): void
    {
        $taken = Account::query()
            ->where('code', $code)
            ->when($exceptAccountId !== null, fn ($query) => $query->whereKeyNot($exceptAccountId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('accounts.code_taken', ['code' => $code])]]);
        }
    }

    /**
     * The parent must exist in the company, be active, share the class of the child and have no
     * postings: an account that was posted to stays a leaf (GL-FR-01).
     *
     * @throws ValidationException
     */
    public function assertValidParent(string $parentId, AccountClass $childClass): Account
    {
        $parent = Account::query()->find($parentId)
            ?? throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_not_found')]]);

        if (! $parent->isActive()) {
            throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_inactive')]]);
        }

        if (JournalLine::query()->where('account_id', $parent->id)->exists()) {
            throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_has_postings')]]);
        }

        if ($parent->class !== $childClass) {
            throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_class_mismatch')]]);
        }

        return $parent;
    }

    /**
     * Moving an account under itself or one of its descendants would form a cycle.
     *
     * @throws ValidationException
     */
    public function assertNoCycle(Account $account, Account $newParent): void
    {
        for ($cursor = $newParent, $guard = 0; $cursor !== null && $guard < 1000; $cursor = $cursor->parent_id === null ? null : Account::query()->find($cursor->parent_id), $guard++) {
            if ($cursor->id === $account->id) {
                throw ValidationException::withMessages(['parent_id' => [__('accounts.parent_cycle')]]);
            }
        }
    }

    /**
     * Merges the posting attributes of GL-FR-02 given in `$input` over `$current` (an account's
     * values, or the defaults) and checks the result: dimension types must be active types of the
     * company, a specific restriction needs an active foreign currency, and only an account that
     * accepts foreign currency can be revalued. Entries that did not change are not re-checked, so
     * an old rule on a type that was deactivated since does not block an unrelated edit.
     *
     * @param  array<string, mixed>  $input  any of allowed_sources, dimension_requirements, currency_restriction, restricted_currency, is_revaluable
     * @param  array<string, mixed>  $current
     * @return array{allowed_sources: list<string>, dimension_requirements: array<string, string>, currency_restriction: string, restricted_currency: string|null, is_revaluable: bool}
     *
     * @throws ValidationException
     */
    public function resolvePostingAttributes(array $input, array $current = self::POSTING_DEFAULTS): array
    {
        $input = array_intersect_key($input, self::POSTING_DEFAULTS);
        $merged = array_replace(self::POSTING_DEFAULTS, array_intersect_key($current, self::POSTING_DEFAULTS), $input);

        if ($merged['currency_restriction'] instanceof CurrencyRestriction) {
            $merged['currency_restriction'] = $merged['currency_restriction']->value;
        }

        if (is_string($merged['restricted_currency'])) {
            $merged['restricted_currency'] = strtoupper($merged['restricted_currency']);
        }

        // Leaving `specific` drops the currency unless the caller says otherwise.
        if (isset($input['currency_restriction']) && $input['currency_restriction'] !== CurrencyRestriction::Specific->value && ! array_key_exists('restricted_currency', $input)) {
            $merged['restricted_currency'] = null;
        }

        $merged['allowed_sources'] = array_values(array_unique($merged['allowed_sources']));
        $merged['dimension_requirements'] = array_filter(
            $merged['dimension_requirements'],
            fn (string $requirement): bool => $requirement !== DimensionRequirement::Optional->value,
        );
        ksort($merged['dimension_requirements']);

        $errors = [];

        if (array_key_exists('dimension_requirements', $input)) {
            $known = $this->dimensionSets->activeTypeKeys($this->company->require());

            foreach ($merged['dimension_requirements'] as $key => $requirement) {
                $unchanged = ($current['dimension_requirements'][$key] ?? null) === $requirement;

                if (! $unchanged && ! in_array($key, $known, true)) {
                    $errors["dimension_requirements.{$key}"] = [__('accounts.dimension_type_unknown', ['key' => $key])];
                }
            }
        }

        $restriction = CurrencyRestriction::from($merged['currency_restriction']);
        $currency = $merged['restricted_currency'];

        if ($restriction === CurrencyRestriction::Specific) {
            if ($currency === null) {
                $errors['restricted_currency'] = [__('accounts.currency_required')];
            } elseif ($currency !== ($current['restricted_currency'] ?? null)) {
                $companyId = $this->company->require();
                $functional = $this->companies->find($companyId)?->functionalCurrency;

                $errors['restricted_currency'] = match (true) {
                    ! $this->currencies->isKnown($currency) => [__('accounts.currency_unknown', ['code' => $currency])],
                    $currency === $functional => [__('accounts.currency_is_functional', ['code' => $currency])],
                    ! $this->currencies->isActive($companyId, $currency) => [__('accounts.currency_inactive', ['code' => $currency])],
                    default => [],
                };

                if ($errors['restricted_currency'] === []) {
                    unset($errors['restricted_currency']);
                }
            }
        } elseif ($currency !== null) {
            $errors['restricted_currency'] = [__('accounts.currency_not_specific')];
        }

        if ($merged['is_revaluable'] && $restriction === CurrencyRestriction::FunctionalOnly) {
            $errors['is_revaluable'] = [__('accounts.revaluable_functional_only')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $merged;
    }
}
