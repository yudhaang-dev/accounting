<?php

namespace App\Modules\Reporting\Http;

use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Platform\Api\ReportParameters;
use Illuminate\Validation\Rule;

/** Validation of the filter parameters that reports share (RP-FR-04). */
final class ReportFilterRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = ReportParameters::rules();

        return [
            'account_id' => $rules['account_id'],
            'dimensions' => $rules['dimensions'],
            'dimensions.*' => ['nullable', 'string', 'size:26'],
            'transaction_type_keys' => $rules['transaction_type_keys'],
            'transaction_type_keys.*' => $rules['transaction_type_keys.*'],
            'source' => ['nullable', Rule::enum(JournalSource::class)],
            'plan_version_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
