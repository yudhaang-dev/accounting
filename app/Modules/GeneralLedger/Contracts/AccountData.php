<?php

namespace App\Modules\GeneralLedger\Contracts;

final readonly class AccountData
{
    /**
     * @param  list<JournalSource>  $allowedSources  posting sources the account accepts (GL-FR-02)
     * @param  array<string, DimensionRequirement>  $dimensionRequirements  dimension type key => requirement; types not listed are optional
     * @param  string|null  $restrictedCurrency  the one foreign currency of a `Specific` restriction
     */
    public function __construct(
        public string $id,
        public string $companyId,
        public string $code,
        public string $name,
        public AccountClass $class,
        public string $normalBalance,
        public ?string $parentId,
        public bool $isLeaf,
        public bool $isActive,
        public array $allowedSources = [JournalSource::Entry, JournalSource::Subledger, JournalSource::System],
        public array $dimensionRequirements = [],
        public CurrencyRestriction $currencyRestriction = CurrencyRestriction::Any,
        public ?string $restrictedCurrency = null,
        public bool $isRevaluable = false,
    ) {}

    public function acceptsSource(JournalSource $source): bool
    {
        return in_array($source, $this->allowedSources, true);
    }

    /** Whether lines in that currency are accepted; `$functionalCurrency` is the company's. */
    public function acceptsCurrency(string $currency, string $functionalCurrency): bool
    {
        return match ($this->currencyRestriction) {
            CurrencyRestriction::FunctionalOnly => $currency === $functionalCurrency,
            CurrencyRestriction::Specific => $currency === $this->restrictedCurrency,
            CurrencyRestriction::Any => true,
        };
    }
}
