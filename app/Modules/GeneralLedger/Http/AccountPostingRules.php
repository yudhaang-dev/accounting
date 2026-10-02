<?php

namespace App\Modules\GeneralLedger\Http;

use App\Modules\GeneralLedger\Contracts\CurrencyRestriction;
use App\Modules\GeneralLedger\Contracts\DimensionRequirement;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use Illuminate\Validation\Rule;

/**
 * Request rules of the posting attributes of an account (GL-FR-02), shared by create and update.
 * Whether dimension types and the currency exist is checked by the action, not here.
 */
final class AccountPostingRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'allowed_sources' => ['sometimes', 'array', 'min:1'],
            'allowed_sources.*' => ['distinct', Rule::enum(JournalSource::class)],
            // Dimension type key => `required`, `optional` or `forbidden`; types not listed are optional.
            'dimension_requirements' => ['sometimes', 'array'],
            'dimension_requirements.*' => [Rule::enum(DimensionRequirement::class)],
            'currency_restriction' => ['sometimes', Rule::enum(CurrencyRestriction::class)],
            // ISO 4217 code of the one foreign currency; only with the restriction `specific`.
            'restricted_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'is_revaluable' => ['sometimes', 'boolean'],
        ];
    }
}
